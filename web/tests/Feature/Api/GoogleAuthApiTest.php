<?php

namespace Tests\Feature\Api;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleAuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGoogleToken(string $email, string $name = 'Google User'): string
    {
        // Generate a 3-part JWT for the test
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'sub' => 'google_sub_' . md5($email),
            'email' => $email,
            'name' => $name,
            'email_verified' => true,
            'exp' => time() + 3600,
        ]));
        $signature = base64_encode('fake_signature');

        return "{$header}.{$payload}.{$signature}";
    }

    public function test_new_google_identity_without_account_returns_needs_role(): void
    {
        $idToken = $this->makeGoogleToken('newuser@gmail.com', 'New User');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'needs_role' => true,
                'email' => 'newuser@gmail.com',
                'name' => 'New User',
            ]);
    }

    public function test_existing_approved_buyer_signs_in_via_google(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'existing.buyer@gmail.com',
        ]);

        $idToken = $this->makeGoogleToken('existing.buyer@gmail.com');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => 'existing.buyer@gmail.com',
                'registration_status' => 'approved',
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_existing_approved_courier_signs_in_via_google(): void
    {
        $courier = LogisticsProvider::factory()->create([
            'email' => 'existing.courier@gmail.com',
            'status' => LogisticsProviderStatus::Approved,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'COU-1234',
        ]);

        $idToken = $this->makeGoogleToken('existing.courier@gmail.com');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'existing.courier@gmail.com',
                'registration_status' => 'approved',
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'COU-1234',
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_pending_buyer_via_google_receives_403_gate(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'pending.buyer@gmail.com',
            'status' => 'pending_approval',
        ]);

        $idToken = $this->makeGoogleToken('pending.buyer@gmail.com');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
            ]);
    }

    public function test_register_buyer_via_google_creates_pending_buyer(): void
    {
        Storage::fake('local');
        $idToken = $this->makeGoogleToken('signup.buyer@gmail.com', 'Signup Buyer');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
            'role' => 'buyer',
            'first_name' => 'Signup',
            'last_name' => 'Buyer',
            'sex' => 'male',
            'contact_no' => '09181234567',
            'birthday' => '1998-01-01',
            'street' => '123 Main St',
            'province_name' => 'Cebu',
            'municipality_name' => 'Cebu City',
            'barangay_name' => 'Lahug',
            'upload_id' => UploadedFile::fake()->create('id.jpg', 300, 'image/jpeg'),
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => 'signup.buyer@gmail.com',
                'registration_status' => 'pending_approval',
            ]);

        $this->assertDatabaseHas('buyers', [
            'email' => 'signup.buyer@gmail.com',
            'status' => 'pending_approval',
        ]);
    }

    public function test_register_courier_via_google_creates_pending_courier(): void
    {
        Storage::fake('local');
        $idToken = $this->makeGoogleToken('signup.courier@gmail.com', 'Signup Courier');

        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => $idToken,
            'role' => 'courier',
            'first_name' => 'Signup',
            'last_name' => 'Courier',
            'sex' => 'female',
            'contact_no' => '09191234567',
            'birthday' => '1996-03-20',
            'street' => '456 Express Rd',
            'vehicle_type' => 'Van',
            'plate_number' => 'VAN-8888',
            'license_number' => 'N02-99-123456',
            'license_expiry' => now()->addYear()->format('Y-m-d'),
            'upload_id' => UploadedFile::fake()->create('id.jpg', 300, 'image/jpeg'),
            'license_doc' => UploadedFile::fake()->create('license.jpg', 300, 'image/jpeg'),
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'signup.courier@gmail.com',
                'registration_status' => 'pending_approval',
            ]);

        $this->assertDatabaseHas('logistics_providers', [
            'email' => 'signup.courier@gmail.com',
            'plate_number' => 'VAN-8888',
            'status' => 'pending_approval',
        ]);
    }

    public function test_invalid_google_token_returns_422(): void
    {
        $response = $this->postJson(route('api.auth.google'), [
            'id_token' => 'invalid-garbage-token',
        ]);

        $response->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_root_and_v1_google_auth_routes_work(): void
    {
        $idToken = $this->makeGoogleToken('v1user@gmail.com');

        $v1Response = $this->postJson(route('api.v1.auth.google'), [
            'id_token' => $idToken,
        ]);
        $v1Response->assertOk()->assertJson(['ok' => true, 'needs_role' => true]);

        $rootResponse = $this->postJson(route('auth.google'), [
            'id_token' => $idToken,
        ]);
        $rootResponse->assertOk()->assertJson(['ok' => true, 'needs_role' => true]);
    }
}
