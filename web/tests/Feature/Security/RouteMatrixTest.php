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
use Laravel\Sanctum\Sanctum;
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
        $routes   = Route::getRoutes()->getRoutesByName();
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
            $hasAuth    = collect($middleware)->contains(
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
        $routes   = Route::getRoutes()->getRoutesByName();
        $v1Routes = array_filter(
            $routes,
            fn ($name) => str_starts_with($name, 'api.v1.'),
            ARRAY_FILTER_USE_KEY,
        );

        $missing = [];
        foreach ($v1Routes as $name => $route) {
            $middleware  = app('router')->gatherRouteMiddleware($route);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRouteProvider')]
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
            'buyer me'               => ['GET',   '/api/v1/buyer/me'],
            'buyer logout'           => ['POST',  '/api/v1/buyer/logout'],
            'buyer orders index'     => ['GET',   '/api/v1/buyer/orders'],
            'buyer orders store'     => ['POST',  '/api/v1/buyer/orders'],
            'buyer addresses index'  => ['GET',   '/api/v1/buyer/addresses'],
            'buyer addresses store'  => ['POST',  '/api/v1/buyer/addresses'],
            'buyer wishlist index'   => ['GET',   '/api/v1/buyer/wishlist'],
            'buyer reviews mine'     => ['GET',   '/api/v1/buyer/reviews/mine'],
            'courier me'             => ['GET',   '/api/v1/courier/me'],
            'courier logout'         => ['POST',  '/api/v1/courier/logout'],
            'courier jobs index'     => ['GET',   '/api/v1/courier/jobs'],
            'seller orders index'    => ['GET',   '/api/v1/seller/orders'],
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

    public function test_buyer_b_cannot_read_buyer_a_orders_milestone(): void
    {
        $buyerA = Buyer::factory()->create(['status' => 'approved']);
        $buyerB = Buyer::factory()->create(['status' => 'approved']);

        $seller = Seller::factory()->create(['status' => 'approved']);
        $order  = Order::query()->create([
            'buyer_id'         => $buyerA->id,
            'reference'        => Order::generateUniqueReference(),
            'shipping_address' => ['name' => 'A', 'full' => 'A'],
            'subtotal_minor'   => 10000,
            'discount_minor'   => 0,
            'total_minor'      => 10000,
        ]);
        $so = SellerOrder::query()->create([
            'order_id'           => $order->id,
            'seller_id'          => $seller->id,
            'subtotal_minor'     => 10000,
            'delivery_fee_minor' => 8500,
            'delivery_area'      => 'Test',
        ]);

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->getJson('/api/v1/buyer/orders/'.$so->id.'/milestone')->assertStatus(403);
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
