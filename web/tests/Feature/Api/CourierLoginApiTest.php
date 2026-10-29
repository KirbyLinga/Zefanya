<?php

namespace Tests\Feature\Api;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourierLoginApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedCourier(array $overrides = []): LogisticsProvider
    {
        return LogisticsProvider::factory()->create(array_merge([
            'email' => 'rider@example.com',
            'password' => Hash::make('password'),
            'status' => LogisticsProviderStatus::Approved,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'RDR-123',
            'is_online' => false,
        ], $overrides));
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson(
            route('api.login.courier'),
            ['email' => $email, 'password' => $password],
            ['Accept' => 'application/json'],
        );
    }

    public function test_an_approved_courier_gets_a_token_and_an_identity(): void
    {
        $courier = $this->approvedCourier();

        $response = $this->login($courier->email);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => $courier->email,
                'registration_status' => 'approved',
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'RDR-123',
            ])
            ->assertJsonStructure(['ok', 'token', 'role', 'name', 'email', 'registration_status', 'vehicle_type', 'plate_number', 'is_online']);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Follow token to /api/me/courier
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.me.courier'));

        $meResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => $courier->email,
            ]);

        // Follow token to /api/me (unified)
        $unifiedMeResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.me'));

        $unifiedMeResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => $courier->email,
            ]);
    }

    public function test_courier_can_sign_out_and_revoke_token(): void
    {
        $courier = $this->approvedCourier();
        $token = $this->login($courier->email)->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson(route('api.logout.courier'));

        $logoutResponse->assertOk()->assertJson(['ok' => true]);

        // The token itself must be deleted from personal_access_tokens
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
    }

    public function test_pending_approval_courier_is_blocked_with_403_gate(): void
    {
        $courier = $this->approvedCourier([
            'status' => LogisticsProviderStatus::PendingApproval,
        ]);

        $response = $this->login($courier->email);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
            ]);
    }

    public function test_rejected_courier_is_blocked_with_403_gate(): void
    {
        $courier = $this->approvedCourier([
            'status' => LogisticsProviderStatus::Rejected,
            'rejection_reason' => 'Invalid driver license uploaded.',
        ]);

        $response = $this->login($courier->email);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
                'message' => 'Invalid driver license uploaded.',
            ]);
    }

    public function test_suspended_courier_is_blocked_with_403_gate(): void
    {
        $courier = $this->approvedCourier([
            'status' => LogisticsProviderStatus::Suspended,
        ]);

        $response = $this->login($courier->email);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'ACCOUNT_SUSPENDED',
                'registration_status' => 'suspended',
            ]);
    }

    public function test_wrong_password_fails_with_422(): void
    {
        $courier = $this->approvedCourier();

        $response = $this->login($courier->email, 'wrong-password');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_buyer_token_cannot_access_courier_me_route(): void
    {
        $buyer = Buyer::factory()->create();
        $token = $buyer->createToken('buyer-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.me.courier'));

        $response->assertStatus(403);
    }

    public function test_v1_courier_routes_work(): void
    {
        $courier = $this->approvedCourier(['email' => 'v1.courier@example.com']);

        $response = $this->postJson(route('api.v1.courier.login'), [
            'email' => 'v1.courier@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true, 'role' => 'courier']);

        $token = $response->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.v1.courier.me'))
            ->assertOk()
            ->assertJson(['ok' => true, 'email' => 'v1.courier@example.com']);
    }
}
