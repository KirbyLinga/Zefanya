<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Registration rejects free-text province/municipality/barangay (address_mode
 * must be api). Account address updates may still use manual mode — without a
 * municipality, SellerOrder::buyerMunicipality() is null and
 * CourierAssignmentService can never pick a delivery rider.
 */
class ManualAddressMunicipalityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_rejects_manual_address_mode(): void
    {
        Storage::fake('local');

        $response = $this->postJson(route('register.buyer.store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'sex' => 'female',
            'email' => 'jane.manual@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'address_mode' => 'manual',
            'province_name' => 'Batangas',
            'municipality_name' => 'Lipa City',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St.',
            'upload_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'terms' => '1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('address_mode');

        $this->assertSame(0, Buyer::query()->count());
    }

    public function test_manual_address_update_requires_a_municipality(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->patch(route('buyer.account.address.update'), [
                'address_mode' => 'manual',
                'province_name' => 'Batangas',
                'municipality_name' => null,
                'barangay_name' => 'Barangay 1',
                'street' => '456 Mabini Ave.',
            ])
            ->assertSessionHasErrors('municipality_name');

        $this->assertNull($buyer->fresh()->municipality_name);
    }
}
