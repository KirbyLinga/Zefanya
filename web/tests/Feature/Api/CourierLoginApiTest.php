<?php

namespace Tests\Feature\Api;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/login/courier` — the same three-answer gate as the buyer's.
 *
 * The things worth proving are identical to `BuyerLoginApiTest`: the three gate
 * statuses told apart, a wrong password indistinguishable from no account,
 * `/me/courier` refusing a buyer's token, and logout revoking only the one token.
 *
 * What is deliberately NOT here: registration or OTP confirmation — those are the
 * web routes, not the API, until the logistics side gets its own API registration
 * endpoint. The seeder (`DemoCourierSeeder`) is the only way into a live courier
 * account right now, which is the same state the buyer half was in before
 * `BuyerRegistrationApiTest` existed.
 */
class CourierLoginApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function courier(array $overrides = []): LogisticsProvider
    {
        return LogisticsProvider::factory()->create(array_merge([
            'email' => 'courier@example.test',
            'password' => 'password',
            'status' => 'approved',
            'email_verified_at' => now(),
        ], $overrides));
    }

    // ── POST /api/login/courier ──────────────────────────────────────────────

    public function test_an_approved_courier_receives_a_token(): void
    {
        $this->courier();

        $response = $this->postJson(route('api.login.courier'), [
            'email' => 'courier@example.test',
            'password' => 'password',
        ])->assertOk();

        $response->assertJsonStructure(['ok', 'token', 'role', 'name', 'email', 'registration_status']);
        $this->assertTrue($response->json('ok'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('courier', $response->json('role'));
        $this->assertSame('approved', $response->json('registration_status'));
    }

    public function test_wrong_password_returns_422_on_the_email_field(): void
    {
        $this->courier();

        $this->postJson(route('api.login.courier'), [
            'email' => 'courier@example.test',
            'password' => 'wrong',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('email');
    }

    public function test_unknown_email_returns_the_same_422(): void
    {
        $this->postJson(route('api.login.courier'), [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('email');
    }

    public function test_a_pending_verification_courier_cannot_get_a_token(): void
    {
        $this->courier([
            'status' => 'pending_verification',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson(route('api.login.courier'), [
            'email' => 'courier@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->assertSame('PENDING_VERIFICATION', $response->json('code'));
        $this->assertSame('pending_verification', $response->json('registration_status'));
        // The handle the app needs to offer the OTP screen.
        $this->assertNotEmpty($response->json('verification_id'));
        // No token: an account that cannot use the app must not receive one.
        $this->assertNull($response->json('token'));
    }

    public function test_a_pending_approval_courier_cannot_get_a_token(): void
    {
        $this->courier([
            'status' => 'pending_approval',
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson(route('api.login.courier'), [
            'email' => 'courier@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->assertSame('PENDING_APPROVAL', $response->json('code'));
        $this->assertSame('pending_approval', $response->json('registration_status'));
        $this->assertNull($response->json('token'));
    }

    public function test_a_rejected_courier_cannot_get_a_token(): void
    {
        $this->courier(['status' => 'rejected']);

        $response = $this->postJson(route('api.login.courier'), [
            'email' => 'courier@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->assertSame('ACCOUNT_REJECTED', $response->json('code'));
        $this->assertNull($response->json('token'));
    }

    // ── GET /api/me/courier ──────────────────────────────────────────────────

    public function test_me_courier_validates_a_couriers_token(): void
    {
        $provider = $this->courier();
        Sanctum::actingAs($provider);

        $response = $this->getJson(route('api.me.courier'))->assertOk();

        $this->assertSame('courier', $response->json('role'));
        $this->assertSame($provider->email, $response->json('email'));
    }

    public function test_me_courier_refuses_a_buyers_token(): void
    {
        // A buyer's token must not pass the courier /me check. The guard is
        // `auth:sanctum`, which accepts any tokenable — the controller check is
        // what makes the distinction.
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        Sanctum::actingAs($buyer);

        $this->getJson(route('api.me.courier'))->assertForbidden();
    }

    public function test_me_courier_requires_a_token(): void
    {
        $this->getJson(route('api.me.courier'))->assertUnauthorized();
    }

    // ── POST /api/logout/courier ─────────────────────────────────────────────

    public function test_logout_revokes_the_courier_token(): void
    {
        $provider = $this->courier();
        $token = $provider->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.logout.courier'))
            ->assertOk();

        // The row itself is gone — that is what makes the token dead.
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
    }

    // ── DemoCourierSeeder ────────────────────────────────────────────────────

    public function test_demo_seeder_produces_a_usable_courier_account(): void
    {
        (new \Database\Seeders\DemoCourierSeeder())->run();

        $response = $this->postJson(route('api.login.courier'), [
            'email' => \Database\Seeders\DemoCourierSeeder::EMAIL,
            'password' => \Database\Seeders\DemoCourierSeeder::PASSWORD,
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('courier', $response->json('role'));
        $this->assertSame('approved', $response->json('registration_status'));
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $seeder = new \Database\Seeders\DemoCourierSeeder();
        $seeder->run();
        $seeder->run();

        $this->assertSame(1, LogisticsProvider::where('email', \Database\Seeders\DemoCourierSeeder::EMAIL)->count());
    }
}
