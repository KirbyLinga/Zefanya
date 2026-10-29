<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BuyerAddressApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createBuyer(): Buyer
    {
        return Buyer::factory()->create();
    }

    public function test_buyer_can_create_and_list_addresses(): void
    {
        $buyer = $this->createBuyer();
        $token = $buyer->createToken('test')->plainTextToken;

        // 1. Create first address (Cebu)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.buyer.addresses.store'), [
                'recipient' => 'Juan Dela Cruz',
                'phone' => '09171234567',
                'label' => 'Home',
                'region' => 'Central Visayas (Region VII)',
                'region_code' => '0700000000',
                'province' => 'Cebu',
                'province_code' => '0702200000',
                'city' => 'Cebu City',
                'city_code' => '0702217000',
                'barangay' => 'Lahug',
                'barangay_code' => '0702217042',
                'street' => '12 Mabini Street',
                'postal_code' => '6000',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.recipient', 'Juan Dela Cruz')
            ->assertJsonPath('data.isDefault', true)
            ->assertJsonPath('data.city', 'Cebu City');

        $firstId = $response->json('data.id');

        // 2. Create second address (NCR - province-less)
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.buyer.addresses.store'), [
                'recipient' => 'Juan Dela Cruz',
                'phone' => '09171234567',
                'label' => 'Work',
                'region' => 'National Capital Region (NCR)',
                'region_code' => '1300000000',
                'province' => null, // NCR province-less
                'city' => 'Makati City',
                'barangay' => 'Bel-Air',
                'street' => 'Ayala Triangle',
                'postal_code' => '1226',
                'is_default' => false,
            ]);

        $response2->assertStatus(201)
            ->assertJsonPath('data.isDefault', false)
            ->assertJsonPath('data.province', 'Metro Manila'); // Fallback label for NCR

        // 3. List addresses
        $listResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.buyer.addresses.index'));

        $listResponse->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_marking_address_as_default_unsets_previous_default(): void
    {
        $buyer = $this->createBuyer();
        $token = $buyer->createToken('test')->plainTextToken;

        $addr1 = $buyer->addresses()->create([
            'recipient' => 'Juan',
            'phone' => '09171112233',
            'label' => 'Home',
            'region' => 'Central Visayas',
            'province' => 'Cebu',
            'city' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $addr2 = $buyer->addresses()->create([
            'recipient' => 'Juan Work',
            'phone' => '09171112233',
            'label' => 'Work',
            'region' => 'Central Visayas',
            'province' => 'Cebu',
            'city' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Street 2',
            'is_default' => false,
        ]);

        // Mark addr2 as default
        $patchResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(route('api.buyer.addresses.default', $addr2));

        $patchResponse->assertOk()
            ->assertJsonPath('data.isDefault', true);

        $this->assertFalse($addr1->fresh()->is_default);
        $this->assertTrue($addr2->fresh()->is_default);
    }

    public function test_deleting_default_address_promotes_next_address_to_default(): void
    {
        $buyer = $this->createBuyer();
        $token = $buyer->createToken('test')->plainTextToken;

        $addr1 = $buyer->addresses()->create([
            'recipient' => 'Juan 1',
            'phone' => '09171112233',
            'label' => 'Home',
            'region' => 'Region VII',
            'city' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $addr2 = $buyer->addresses()->create([
            'recipient' => 'Juan 2',
            'phone' => '09171112233',
            'label' => 'Work',
            'region' => 'Region VII',
            'city' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Street 2',
            'is_default' => false,
        ]);

        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson(route('api.buyer.addresses.destroy', $addr1));

        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('buyer_addresses', ['id' => $addr1->id]);
        $this->assertTrue($addr2->fresh()->is_default);
    }

    public function test_buyer_cannot_access_another_buyers_address(): void
    {
        $buyer1 = $this->createBuyer();
        $buyer2 = $this->createBuyer();

        $addr1 = $buyer1->addresses()->create([
            'recipient' => 'Buyer 1',
            'phone' => '09171112233',
            'region' => 'Region VII',
            'city' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $token2 = $buyer2->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson(route('api.buyer.addresses.show', $addr1))
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token2}")
            ->deleteJson(route('api.buyer.addresses.destroy', $addr1))
            ->assertStatus(403);
    }

    public function test_courier_cannot_access_buyer_addresses(): void
    {
        $courier = LogisticsProvider::factory()->create();
        $token = $courier->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.buyer.addresses.index'))
            ->assertStatus(403);
    }

    public function test_address_to_snapshot_generates_immutable_structure(): void
    {
        $buyer = $this->createBuyer();
        $addr = $buyer->addresses()->create([
            'recipient' => 'Juan Dela Cruz',
            'phone' => '09171234567',
            'label' => 'Home',
            'region' => 'National Capital Region (NCR)',
            'province' => null,
            'city' => 'Taguig City',
            'barangay' => 'BGC',
            'street' => '5th Ave High Street',
            'postal_code' => '1634',
            'is_default' => true,
        ]);

        $snapshot = $addr->toSnapshot();

        $this->assertEquals($addr->id, $snapshot['address_id']);
        $this->assertEquals('Juan Dela Cruz', $snapshot['recipient']);
        $this->assertEquals('Metro Manila', $snapshot['province']);
        $this->assertStringContainsString('5th Ave High Street', $snapshot['summary']);
    }
}
