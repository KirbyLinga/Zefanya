<?php

namespace Tests\Feature\Api;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourierRegistrationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'middle_initial' => 'P',
            'sex' => 'male',
            'email' => 'juan.rider@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'contact_no' => '09171234567',
            'birthday' => '1995-05-15',
            'company' => 'Express Logistics',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC-1234',
            'license_number' => 'N01-12-345678',
            'license_expiry' => now()->addYear()->format('Y-m-d'),
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Diliman',
            'street' => '123 Katipunan Ave',
            'hub' => 'QC Hub',
            'upload_id' => UploadedFile::fake()->create('id.jpg', 500, 'image/jpeg'),
            'license_doc' => UploadedFile::fake()->create('license.jpg', 500, 'image/jpeg'),
            'or_cr_doc' => UploadedFile::fake()->create('or_cr.jpg', 500, 'image/jpeg'),
        ], $overrides);
    }

    public function test_courier_registration_succeeds_and_creates_pending_provider(): void
    {
        Storage::fake('local');

        $response = $this->postJson(route('api.register.courier.store'), $this->validPayload());

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'juan.rider@example.com',
                'registration_status' => 'pending_approval',
            ]);

        $this->assertDatabaseHas('logistics_providers', [
            'email' => 'juan.rider@example.com',
            'plate_number' => 'ABC-1234',
            'vehicle_type' => 'Motorcycle',
            'status' => 'pending_approval',
            'is_online' => false,
        ]);
    }

    public function test_courier_registration_alias_register_logistics_succeeds(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            route('api.register.logistics.store'),
            $this->validPayload(['email' => 'alias.rider@example.com', 'plate_number' => 'XYZ-9876'])
        );

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'alias.rider@example.com',
                'registration_status' => 'pending_approval',
            ]);
    }

    public function test_courier_registration_v1_route_succeeds(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            route('api.v1.courier.register'),
            $this->validPayload(['email' => 'v1.rider@example.com', 'plate_number' => 'V1-1234'])
        );

        $response->assertStatus(201)
            ->assertJson(['ok' => true, 'role' => 'courier']);
    }

    public function test_registration_fails_when_id_upload_is_missing(): void
    {
        $payload = $this->validPayload();
        unset($payload['upload_id']);

        $response = $this->postJson(route('api.register.courier.store'), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['upload_id']);
    }

    public function test_registration_fails_when_underage(): void
    {
        $payload = $this->validPayload([
            'birthday' => now()->subYears(17)->format('Y-m-d'),
        ]);

        $response = $this->postJson(route('api.register.courier.store'), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['birthday']);
    }

    public function test_registration_fails_when_license_is_expired(): void
    {
        $payload = $this->validPayload([
            'license_expiry' => now()->subDays(1)->format('Y-m-d'),
        ]);

        $response = $this->postJson(route('api.register.courier.store'), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['license_expiry']);
    }

    public function test_registration_fails_on_duplicate_plate_number(): void
    {
        LogisticsProvider::factory()->create([
            'plate_number' => 'ABC-1234',
        ]);

        $response = $this->postJson(route('api.register.courier.store'), $this->validPayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number']);
    }
}
