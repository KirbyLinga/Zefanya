<?php

namespace Tests\Feature\Security;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H3: every API route has a rate limit, and the named buckets are keyed on the
 * actor rather than the URL.
 *
 * These are behavioural tests, not configuration reads: each one sends the
 * request the app would send and asserts the 429 arrives with Retry-After.
 * The route guard is the only static test, and it exists because a new v1
 * route would otherwise be unlimited by accident.
 */
class ApiRateLimitingTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ---- the guard ---------------------------------------------------------

    public function test_every_api_v1_route_inherits_or_declares_a_throttle(): void
    {
        $router = app('router');
        $routes = collect($router->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));

        $this->assertNotEmpty($routes, 'No /api/v1 routes were registered.');

        foreach ($routes as $route) {
            $middleware = $router->gatherRouteMiddleware($route);

            $hasThrottle = collect($middleware)->contains(
                fn ($name): bool => is_string($name) && str_contains($name, 'ThrottleRequests'),
            );

            $this->assertTrue(
                $hasThrottle,
                "Route [{$route->methods()[0]}] /{$route->uri()} has no throttle middleware.",
            );
        }
    }

    public function test_the_sensitive_v1_routes_name_their_stricter_bucket(): void
    {
        $router = app('router');

        $expected = [
            'api.v1.auth.google' => 'api-google',
            'api.v1.buyer.login' => 'api-login',
            'api.v1.buyer.register' => 'api-register',
            'api.v1.buyer.reviews.upsert' => 'api-uploads',
            'api.v1.courier.login' => 'api-login',
            'api.v1.courier.register' => 'api-register',
            'api.v1.courier.jobs.milestone' => 'api-delivery-verify',
        ];

        foreach ($expected as $name => $bucket) {
            $middleware = $router->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $this->assertContains(
                'Illuminate\Routing\Middleware\ThrottleRequests:'.$bucket,
                $middleware,
                "Route [$name] does not use the [$bucket] bucket.",
            );
        }
    }

    // ---- login -------------------------------------------------------------

    public function test_v1_login_allows_five_attempts_per_email_and_then_429s(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // Changing the case of the address must not buy a fresh bucket.
            $email = $attempt % 2 === 0 ? 'Rate.Limit@Example.com' : 'rate.limit@example.com';

            $this->postJson(route('api.v1.buyer.login'), [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(route('api.v1.buyer.login'), [
            'email' => 'RATE.LIMIT@EXAMPLE.COM',
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_login_bucket_is_per_email_so_one_account_cannot_lock_out_another(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.v1.buyer.login'), [
                'email' => 'blocked@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(route('api.v1.buyer.login'), [
            'email' => 'blocked@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // Same address, different mailbox: still allowed.
        $this->postJson(route('api.v1.buyer.login'), [
            'email' => 'somebody.else@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_v1_and_legacy_login_share_one_bucket(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.v1.buyer.login'), [
                'email' => 'shared.bucket@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(route('api.login.buyer'), [
            'email' => 'shared.bucket@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ---- registration and Google ------------------------------------------

    public function test_v1_registration_is_limited_to_five_per_address(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.v1.buyer.register'), [])->assertStatus(422);
        }

        $this->postJson(route('api.v1.buyer.register'), [])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_google_sign_in_is_limited_to_ten_per_address(): void
    {
        config(['services.google.client_ids' => '']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson(route('api.v1.auth.google'), ['id_token' => 'fake-token'])
                ->assertStatus(503);
        }

        $this->postJson(route('api.v1.auth.google'), ['id_token' => 'fake-token'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ---- delivery verification ---------------------------------------------

    public function test_delivery_verification_is_limited_to_ten_per_courier(): void
    {
        $courier = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $job = $this->job($courier);

        Sanctum::actingAs($courier);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->patchJson(route('api.v1.courier.jobs.milestone', $job), [
                'milestone' => 'NOT_A_MILESTONE',
            ])->assertStatus(422);
        }

        $this->patchJson(route('api.v1.courier.jobs.milestone', $job), [
            'milestone' => 'NOT_A_MILESTONE',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_delivery_bucket_does_not_follow_a_second_courier(): void
    {
        $courier = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $job = $this->job($courier);

        Sanctum::actingAs($courier);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->patchJson(route('api.v1.courier.jobs.milestone', $job), [
                'milestone' => 'NOT_A_MILESTONE',
            ])->assertStatus(422);
        }

        $other = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other);

        // The job belongs to the first courier, so this is a 403 (ownership),
        // not a 429: the second courier's bucket is still empty.
        $this->patchJson(route('api.v1.courier.jobs.milestone', $job), [
            'milestone' => 'NOT_A_MILESTONE',
        ])->assertStatus(403);
    }

    // ---- the 60/min default and the upload bucket --------------------------

    public function test_the_v1_default_is_sixty_requests_per_user(): void
    {
        $buyer = Buyer::factory()->create();
        $token = $buyer->createToken('rate-limit-test')->plainTextToken;

        for ($request = 0; $request < 60; $request++) {
            $this->withToken($token)->getJson(route('api.v1.buyer.me'))->assertOk();
        }

        $this->withToken($token)->getJson(route('api.v1.buyer.me'))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_default_bucket_is_keyed_by_user_not_by_address(): void
    {
        $first = Buyer::factory()->create();
        $second = Buyer::factory()->create();
        $firstToken = $first->createToken('first-phone')->plainTextToken;
        $secondToken = $second->createToken('second-phone')->plainTextToken;

        for ($request = 0; $request < 60; $request++) {
            $this->withToken($firstToken)->getJson(route('api.v1.buyer.me'))->assertOk();
        }

        $this->withToken($firstToken)->getJson(route('api.v1.buyer.me'))->assertStatus(429);

        $this->app['auth']->forgetGuards();

        // Both buyers share 127.0.0.1 in tests; the second still has a budget.
        $this->withToken($secondToken)->getJson(route('api.v1.buyer.me'))->assertOk();
    }

    public function test_a_guest_is_limited_per_address_on_v1(): void
    {
        for ($request = 0; $request < 60; $request++) {
            $this->getJson(route('api.v1.psgc.regions'))->assertOk();
        }

        $this->getJson(route('api.v1.psgc.regions'))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_review_uploads_are_limited_to_twenty_per_user(): void
    {
        $buyer = Buyer::factory()->create();
        $token = $buyer->createToken('review-upload-test')->plainTextToken;

        // The ids do not exist, so the route answers 404. That is enough: the
        // throttle runs before route-model binding, which is the point.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withToken($token)->patchJson(
                '/api/v1/buyer/orders/999999/items/999999/review',
                ['rating' => 5],
            )->assertStatus(404);
        }

        $this->withToken($token)->patchJson(
            '/api/v1/buyer/orders/999999/items/999999/review',
            ['rating' => 5],
        )->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ---- the password-mutation bucket --------------------------------------

    public function test_the_password_route_is_limited_to_three_per_user(): void
    {
        $buyer = Buyer::factory()->create();
        $token = $buyer->createToken('password-limit-test')->plainTextToken;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->withToken($token)->postJson(route('api.account.password'), [
                'current_password' => 'NotThePassword1!',
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ])->assertStatus(422);
        }

        $this->withToken($token)->postJson(route('api.account.password'), [
            'current_password' => 'NotThePassword1!',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ---- helpers -----------------------------------------------------------

    private function job(LogisticsProvider $courier): SellerOrder
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['name' => $buyer->fullName(), 'full' => 'Rate limit test address'],
            'subtotal_minor' => 50000,
            'discount_minor' => 0,
            'total_minor' => 50000,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 50000,
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => 8500,
            'delivery_area' => 'Test area',
        ]);

        $sellerOrder->forceFill(['status' => 'OUT_FOR_DELIVERY'])->save();

        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        return $sellerOrder->fresh();
    }
}
