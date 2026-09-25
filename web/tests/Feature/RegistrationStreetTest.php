<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies that street / house number is always required for both buyer
 * and seller registration, in both API and manual address modes.
 *
 * Coverage:
 *   - Registration succeeds with a street in API mode (buyer + seller)
 *   - Registration succeeds with a street in manual mode (buyer + seller)
 *   - Missing street fails validation in API mode (buyer + seller)
 *   - Missing street fails validation in manual mode (buyer + seller)
 *   - street max:255 is enforced (buyer + seller)
 *   - address_detail is optional (buyer + seller)
 *   - Stored row contains the submitted street value (buyer + seller)
 *   - Admin registrations view shows the full street for a buyer and seller
 */
class RegistrationStreetTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Shared helpers ────────────────────────────────────────────────────

    private function fakeId(): UploadedFile
    {
        Storage::fake('local');

        return UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');
    }

    private function buyerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'sex' => 'female',
            'email' => 'jane'.uniqid().'@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
            'upload_id' => $this->fakeId(),
        ], $overrides);
    }

    private function sellerPayload(array $overrides = []): array
    {
        $cat = Category::query()->value('id');

        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'sex' => 'male',
            'email' => 'juan'.uniqid().'@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no' => '09181234567',
            'birthday' => '1990-01-15',
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '456 Mabini Ave.',
            'business_name' => 'Test Store '.uniqid(),
            'line_of_business_id' => $cat,
            'upload_id' => $this->fakeId(),
            'business_permit' => $this->fakeId(),
        ], $overrides);
    }

    // ── Buyer: API mode ───────────────────────────────────────────────────

    public function test_buyer_registration_succeeds_with_street_in_api_mode(): void
    {
        $response = $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload(['address_mode' => 'api'])
        );

        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buyer_registration_fails_without_street_in_api_mode(): void
    {
        $response = $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload(['address_mode' => 'api', 'street' => ''])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    public function test_buyer_registration_fails_without_street_when_null(): void
    {
        $payload = $this->buyerPayload();
        unset($payload['street']);

        $response = $this->postJson(route('register.buyer.store'), $payload);

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    // ── Buyer: manual mode ────────────────────────────────────────────────

    public function test_buyer_registration_succeeds_with_street_in_manual_mode(): void
    {
        $response = $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload([
                'address_mode' => 'manual',
                'province' => null,
                'province_name' => 'Batangas',
                'municipality' => null,
                'municipality_name' => 'Lipa City',
                'barangay' => null,
                'barangay_name' => 'Barangay 1',
                'street' => '789 Luna St.',
            ])
        );

        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buyer_registration_fails_without_street_in_manual_mode(): void
    {
        $response = $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload([
                'address_mode' => 'manual',
                'province' => null,
                'municipality' => null,
                'barangay' => null,
                'street' => '',
            ])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    // ── Buyer: field constraints ──────────────────────────────────────────

    public function test_buyer_street_max_255_is_enforced(): void
    {
        $response = $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload(['street' => str_repeat('a', 256)])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    public function test_buyer_address_detail_is_optional(): void
    {
        $payload = $this->buyerPayload();
        unset($payload['address_detail']);

        $response = $this->postJson(route('register.buyer.store'), $payload);

        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buyer_registration_stores_street_value(): void
    {
        $street = '99 Bonifacio Blvd., Purok 5';

        $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload(['street' => $street])
        )->assertStatus(200);

        $this->assertDatabaseHas('buyers', ['street' => $street]);
    }

    public function test_buyer_registration_stores_address_detail(): void
    {
        $detail = 'Unit 3B, near the market';

        $this->postJson(
            route('register.buyer.store'),
            $this->buyerPayload(['address_detail' => $detail])
        )->assertStatus(200);

        $this->assertDatabaseHas('buyers', ['address_detail' => $detail]);
    }

    // ── Seller: API mode ──────────────────────────────────────────────────

    public function test_seller_registration_succeeds_with_street_in_api_mode(): void
    {
        $response = $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload(['address_mode' => 'api'])
        );

        $response->assertStatus(201);
    }

    public function test_seller_registration_fails_without_street_in_api_mode(): void
    {
        $response = $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload(['address_mode' => 'api', 'street' => ''])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    public function test_seller_registration_fails_without_street_when_null(): void
    {
        $payload = $this->sellerPayload();
        unset($payload['street']);

        $response = $this->postJson(route('register.seller.store'), $payload);

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    // ── Seller: manual mode ───────────────────────────────────────────────

    public function test_seller_registration_succeeds_with_street_in_manual_mode(): void
    {
        $response = $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload([
                'address_mode' => 'manual',
                'province' => null,
                'province_name' => 'Cavite',
                'municipality' => null,
                'municipality_name' => 'Bacoor',
                'barangay' => null,
                'barangay_name' => 'Barangay 2',
                'street' => '12 Aguinaldo Hwy.',
            ])
        );

        $response->assertStatus(201);
    }

    public function test_seller_registration_fails_without_street_in_manual_mode(): void
    {
        $response = $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload([
                'address_mode' => 'manual',
                'province' => null,
                'municipality' => null,
                'barangay' => null,
                'street' => '',
            ])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    // ── Seller: field constraints ─────────────────────────────────────────

    public function test_seller_street_max_255_is_enforced(): void
    {
        $response = $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload(['street' => str_repeat('b', 256)])
        );

        $response->assertStatus(422)->assertJsonValidationErrorFor('street');
    }

    public function test_seller_address_detail_is_optional(): void
    {
        $payload = $this->sellerPayload();
        unset($payload['address_detail']);

        $response = $this->postJson(route('register.seller.store'), $payload);

        $response->assertStatus(201);
    }

    public function test_seller_registration_stores_street_value(): void
    {
        $street = '7 Kalayaan St., Brgy. Uno';

        $this->postJson(
            route('register.seller.store'),
            $this->sellerPayload(['street' => $street])
        )->assertStatus(201);

        $this->assertDatabaseHas('sellers', ['street' => $street]);
    }

    // ── Admin registrations view shows full address ───────────────────────

    public function test_admin_registrations_view_shows_street_for_buyer(): void
    {
        $buyer = Buyer::factory()->create([
            'street' => '55 Mabini Ave.',
            'barangay_name' => 'Brgy. Uno',
            'municipality_name' => 'Calamba',
            'province_name' => 'Laguna',
            'address_detail' => 'Near the church',
            'status' => 'pending_approval',
        ]);

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.registrations.index', ['view' => $buyer->id, 'type' => 'buyer']))
            ->assertOk()
            ->assertSee('55 Mabini Ave.')
            ->assertSee('Brgy. Uno')
            ->assertSee('Calamba')
            ->assertSee('Laguna');
    }

    public function test_admin_registrations_view_shows_street_for_seller(): void
    {
        $seller = Seller::factory()->create([
            'street' => '88 Rizal Blvd.',
            'barangay_name' => 'Brgy. Dos',
            'municipality_name' => 'Batangas City',
            'province_name' => 'Batangas',
            'status' => 'pending_approval',
        ]);

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.registrations.index', ['view' => $seller->id, 'type' => 'seller']))
            ->assertOk()
            ->assertSee('88 Rizal Blvd.')
            ->assertSee('Batangas City');
    }
}
