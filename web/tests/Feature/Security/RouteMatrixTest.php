<?php

namespace Tests\Feature\Security;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * H6: route test matrix.
 *
 * Guard test: every /api/v1 route is either on the allowlist (public) or
 * behind auth:sanctum middleware. Plus data-driven 401/403/422 checks.
 */
class RouteMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Routes that are explicitly public (no token required).
     */
    private const ALLOWLIST = [
        'api.v1.buyer.register',
        'api.v1.buyer.login',
        'api.v1.courier.register',
        'api.v1.courier.login',
        'api.v1.auth.google',
        'api.v1.psgc.regions',
        'api.v1.psgc.provinces',
        'api.v1.psgc.cities',
        'api.v1.psgc.barangays',
    ];

    // ── Guard: every /api/v1 route has auth or is on the allowlist ────────

    public function test_every_v1_route_has_auth_or_is_on_allowlist(): void
    {
        $routes = Route::getRoutes()->getRoutesByName();
        $v1Routes = array_filter(
            $routes,
            fn ($name) => str_starts_with($name, 'api.v1.'),
            ARRAY_FILTER_USE_KEY,
        );

        $missing = [];
        foreach ($v1Routes as $name => $route) {
            if (in_array($name, self::ALLOWLIST, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $hasAuth = collect($middleware)->contains(
                fn ($m) => str_contains($m, 'auth:sanctum') || $m === 'auth',
            );
            if (! $hasAuth) {
                $missing[] = $name;
            }
        }

        $this->assertEmpty(
            $missing,
            'These /api/v1 routes lack auth middleware: '.implode(', ', $missing),
        );
    }

    // ── Guard: every /api/v1 route has throttle (or inherits api-default) ─

    public function test_every_v1_route_has_throttle_middleware(): void
    {
        $routes = Route::getRoutes()->getRoutesByName();
        $v1Routes = array_filter(
            $routes,
            fn ($name) => str_starts_with($name, 'api.v1.'),
            ARRAY_FILTER_USE_KEY,
        );

        $missing = [];
        foreach ($v1Routes as $name => $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);
            $hasThrottle = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests'),
            );
            if (! $hasThrottle) {
                $missing[] = $name;
            }
        }

        // api-default is appended to the api group in bootstrap/app.php so every
        // gatherRouteMiddleware() surfaces it as a FQCN with 'ThrottleRequests'.
        $this->assertEmpty(
            $missing,
            'These /api/v1 routes lack throttle middleware: '.implode(', ', $missing),
        );
    }

    // ── Unauthenticated → 401 ─────────────────────────────────────────────

    #[DataProvider('protectedRouteProvider')]
    public function test_unauthenticated_returns_401(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            "Expected 401 for {$method} {$uri}, got {$response->getStatusCode()}",
        );
    }

    public static function protectedRouteProvider(): array
    {
        return [
            'buyer me' => ['GET',   '/api/v1/buyer/me'],
            'buyer logout' => ['POST',  '/api/v1/buyer/logout'],
            'buyer orders index' => ['GET',   '/api/v1/buyer/orders'],
            'buyer orders store' => ['POST',  '/api/v1/buyer/orders'],
            'buyer delivery pass' => ['POST',  '/api/v1/buyer/orders/1/delivery-pass'],
            'buyer delivery proof' => ['GET',   '/api/v1/buyer/orders/1/delivery-proof'],
            'buyer addresses index' => ['GET',   '/api/v1/buyer/addresses'],
            'buyer addresses store' => ['POST',  '/api/v1/buyer/addresses'],
            'buyer wishlist index' => ['GET',   '/api/v1/buyer/wishlist'],
            'buyer reviews mine' => ['GET',   '/api/v1/buyer/reviews/mine'],
            'courier me' => ['GET',   '/api/v1/courier/me'],
            'courier logout' => ['POST',  '/api/v1/courier/logout'],
            'courier jobs index' => ['GET',   '/api/v1/courier/jobs'],
            'seller orders index' => ['GET',   '/api/v1/seller/orders'],
            'admin logistics pending' => ['GET',  '/api/v1/admin/logistics/pending'],
        ];
    }

    // ── Wrong role → 403 ──────────────────────────────────────────────────

    public function test_buyer_cannot_reach_courier_jobs(): void
    {
        Sanctum::actingAs(Buyer::factory()->create(['status' => 'approved']), [], 'sanctum');
        $this->getJson('/api/v1/courier/jobs')->assertStatus(403);
    }

    public function test_courier_cannot_reach_seller_orders(): void
    {
        $courier = LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($courier, [], 'sanctum');
        $this->getJson('/api/v1/seller/orders')->assertStatus(403);
    }

    public function test_buyer_cannot_reach_admin_logistics(): void
    {
        Sanctum::actingAs(Buyer::factory()->create(['status' => 'approved']), [], 'sanctum');
        $this->getJson('/api/v1/admin/logistics/pending')->assertStatus(403);
    }

    // ── Another user's resource → 403 ─────────────────────────────────────

    private function parcelFor(Buyer $buyer, string $status = 'OUT_FOR_DELIVERY', ?string $proofPath = null): SellerOrder
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['name' => 'A', 'full' => 'A'],
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'total_minor' => 10000,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 10000,
            'delivery_fee_minor' => 8500,
            'delivery_area' => 'Test',
        ]);

        $sellerOrder->forceFill([
            'status' => $status,
            'delivery_proof_path' => $proofPath,
        ])->save();

        return $sellerOrder->fresh();
    }

    public function test_buyer_b_cannot_read_buyer_a_orders_milestone(): void
    {
        $buyerA = Buyer::factory()->create(['status' => 'approved']);
        $buyerB = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyerA);

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->getJson('/api/v1/buyer/orders/'.$so->id.'/milestone')->assertStatus(403);
    }

    public function test_buyer_b_cannot_request_buyer_a_delivery_pass(): void
    {
        $buyerA = Buyer::factory()->create(['status' => 'approved']);
        $buyerB = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyerA);

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->postJson('/api/v1/buyer/orders/'.$so->id.'/delivery-pass')->assertStatus(403);
    }

    public function test_buyer_b_cannot_download_buyer_a_delivery_proof(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('delivery-proofs/proof.jpg', 'fake-image-bytes');

        $buyerA = Buyer::factory()->create(['status' => 'approved']);
        $buyerB = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyerA, 'DELIVERED', 'delivery-proofs/proof.jpg');

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->getJson('/api/v1/buyer/orders/'.$so->id.'/delivery-proof')->assertStatus(403);
    }

    // ── Delivery pass / proof ─────────────────────────────────────────────

    public function test_delivery_pass_is_refused_before_out_for_delivery(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer, 'PLACED');

        Sanctum::actingAs($buyer, [], 'sanctum');
        $this->postJson('/api/v1/buyer/orders/'.$so->id.'/delivery-pass')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This parcel is not out for delivery.');
    }

    public function test_delivery_pass_returns_a_six_digit_code_and_signed_token(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer);

        Sanctum::actingAs($buyer, [], 'sanctum');
        $response = $this->postJson('/api/v1/buyer/orders/'.$so->id.'/delivery-pass')
            ->assertOk()
            ->assertJsonStructure(['data' => ['code', 'token', 'expires_at']]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('data.code'));
        $this->assertNotSame('', (string) $response->json('data.token'));
    }

    public function test_delivery_proof_returns_the_owners_stored_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('delivery-proofs/proof.jpg', 'fake-image-bytes');

        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer, 'DELIVERED', 'delivery-proofs/proof.jpg');

        Sanctum::actingAs($buyer, [], 'sanctum');
        $this->get('/api/v1/buyer/orders/'.$so->id.'/delivery-proof')
            ->assertOk()
            ->assertDownload('proof.jpg');
    }

    public function test_delivery_proof_is_404_when_the_parcel_has_no_photo(): void
    {
        Storage::fake('local');

        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer, 'DELIVERED');

        Sanctum::actingAs($buyer, [], 'sanctum');
        $this->getJson('/api/v1/buyer/orders/'.$so->id.'/delivery-proof')
            ->assertStatus(404);
    }

    public function test_delivery_pass_is_throttled_after_the_api_default_floor(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer);

        Sanctum::actingAs($buyer, [], 'sanctum');

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/buyer/orders/'.$so->id.'/delivery-pass')->assertOk();
        }

        $this->postJson('/api/v1/buyer/orders/'.$so->id.'/delivery-pass')->assertStatus(429);
    }

    public function test_delivery_proof_is_throttled_after_the_api_default_floor(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('delivery-proofs/proof.jpg', 'fake-image-bytes');

        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $so = $this->parcelFor($buyer, 'DELIVERED', 'delivery-proofs/proof.jpg');

        Sanctum::actingAs($buyer, [], 'sanctum');

        for ($i = 0; $i < 60; $i++) {
            $this->get('/api/v1/buyer/orders/'.$so->id.'/delivery-proof')->assertOk();
        }

        $this->get('/api/v1/buyer/orders/'.$so->id.'/delivery-proof')->assertStatus(429);
    }

    // ── Invalid input → 422 ───────────────────────────────────────────────

    public function test_invalid_buyer_registration_returns_422(): void
    {
        $this->postJson('/api/v1/buyer/register', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_invalid_buyer_login_returns_422(): void
    {
        $this->postJson('/api/v1/buyer/login', ['email' => ''])
            ->assertStatus(422);
    }

    // ── Success ───────────────────────────────────────────────────────────

    public function test_authenticated_buyer_can_list_orders(): void
    {
        Sanctum::actingAs(Buyer::factory()->create(['status' => 'approved']), [], 'sanctum');
        $this->getJson('/api/v1/buyer/orders')->assertOk();
    }

    public function test_authenticated_approved_courier_can_list_jobs(): void
    {
        $courier = LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($courier, [], 'sanctum');
        $this->getJson('/api/v1/courier/jobs')->assertOk();
    }
}
