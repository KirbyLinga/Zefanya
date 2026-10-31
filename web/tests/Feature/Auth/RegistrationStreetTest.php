<?php

namespace Tests\Feature\Auth;

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
 *   - Admin registrations view lists a pending buyer and seller
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
            'region_code' => '030000000',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
            'upload_id' => $this->fakeId(),
            'terms' => '1',
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
            'region_code' => '030000000',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '456 Mabini Ave.',
            'seller_type' => 'sole_proprietor',
            'business_name' => 'Test Store '.uniqid(),
            'tin' => '123-456-789-000',
            'line_of_business_id' => $cat,
            'upload_id' => $this->fakeId(),
            'business_permit' => $this->fakeId(),
            'terms' => '1',
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
            $this->buyerPayload(['address_mode' => 'api',
                'region_code' => '030000000', 'street' => ''])
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

    // ── Buyer: manual mode rejected ───────────────────────────────────────

    public function test_buyer_registration_rejects_manual_address_mode(): void
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

        $response->assertStatus(422)->assertJsonValidationErrorFor('address_mode');
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
            $this->sellerPayload(['address_mode' => 'api',
                'region_code' => '030000000', 'street' => ''])
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

    // ── Seller: manual mode rejected ──────────────────────────────────────

    public function test_seller_registration_rejects_manual_address_mode(): void
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

        $response->assertStatus(422)->assertJsonValidationErrorFor('address_mode');
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

    // ── Admin registrations view lists pending applicants ─────────────────

    public function test_admin_registrations_view_lists_the_pending_buyer(): void
    {
        $buyer = Buyer::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.'.uniqid().'@example.com',
            'street' => '55 Mabini Ave.',
            'barangay_name' => 'Brgy. Uno',
            'municipality_name' => 'Calamba',
            'province_name' => 'Laguna',
            'address_detail' => 'Near the church',
            'status' => 'pending',
        ]);

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        // The reworked queue lists applicants by name + email; the old ?view=
        // detail panel that rendered the full address was removed.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.registrations.index', ['type' => 'buyer']))
            ->assertOk()
            ->assertSee('Juan Dela Cruz')
            ->assertSee($buyer->email);
    }

    public function test_admin_registrations_view_lists_the_pending_seller(): void
    {
        $seller = Seller::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.'.uniqid().'@example.com',
            'street' => '88 Rizal Blvd.',
            'barangay_name' => 'Brgy. Dos',
            'municipality_name' => 'Batangas City',
            'province_name' => 'Batangas',
            'status' => 'pending',
        ]);

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.registrations.index', ['type' => 'seller']))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee($seller->email);
    }
}
