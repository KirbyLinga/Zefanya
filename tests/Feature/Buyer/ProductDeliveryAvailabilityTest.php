<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class ProductDeliveryAvailabilityTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active', 'weight_grams' => 500]);

        return [$buyer, $product];
    }

    public function test_guest_is_prompted_to_sign_in_without_seeing_an_address(): void
    {
        [$buyer, $product] = $this->fixture();

        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSeeText('Sign in to check whether this item can be delivered to your address.')
            ->assertDontSee('data-delivery-available=', false);
    }

    public function test_supported_route_is_shown_before_checkout_without_creating_an_order_or_route(): void
    {
        [$buyer, $product] = $this->fixture();

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('data-delivery-available="true"', false)->assertSeeText('Delivery available')
            ->assertSeeText('First, San Fernando, Pampanga');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('parcel_routes', 0);
    }

    public function test_disabled_seller_carrier_is_not_presented_as_available(): void
    {
        [$buyer, $product] = $this->fixture();
        SellerShippingProvider::query()->where('seller_id', $product->seller_id)->update(['is_enabled' => false]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('data-delivery-available="false"', false)->assertSeeText('Delivery unavailable to this address');
    }

    public function test_missing_required_partnership_and_connections_make_cross_province_delivery_unavailable(): void
    {
        [$buyer, $product] = $this->fixture();
        $buyer->forceFill(['province_code' => '072200000', 'province_name' => 'Cebu', 'barangay_code' => '0722170001'])->save();

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('data-delivery-available="false"', false);
    }

    public function test_incomplete_buyer_address_requests_an_address_instead_of_claiming_availability(): void
    {
        [$buyer, $product] = $this->fixture();
        $buyer->forceFill(['barangay_code' => null])->save();

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSeeText('Add a complete delivery address to check availability.')
            ->assertDontSee('data-delivery-available=', false);
    }

    public function test_default_saved_address_is_used_without_mutating_the_profile_or_using_another_buyers_address(): void
    {
        [$buyer, $product] = $this->fixture();
        $address = $buyer->only(['province_code', 'province_name', 'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name']);
        $buyer->addresses()->create($address + ['address_mode' => 'manual', 'street' => 'Default street', 'is_default' => true, 'default_marker' => 1]);
        $buyer->forceFill(['barangay_code' => '0305409999', 'barangay_name' => 'Unsupported profile address'])->save();
        Buyer::factory()->create()->addresses()->create([
            'address_mode' => 'manual', 'province_code' => '072200000', 'province_name' => 'Foreign private province',
            'barangay_code' => '0722170001', 'is_default' => true, 'default_marker' => 1,
        ]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('data-delivery-available="true"', false)->assertSeeText('First, San Fernando, Pampanga')
            ->assertDontSeeText('Foreign private province')->assertDontSeeText('Unsupported profile address');
        $this->assertSame('0305409999', $buyer->fresh()->barangay_code);
    }

    public function test_incomplete_seller_pickup_address_does_not_blame_the_buyers_complete_address(): void
    {
        [$buyer, $product] = $this->fixture();
        $product->seller->forceFill(['barangay_code' => null])->save();

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product))->assertOk()
            ->assertSeeText('Delivery availability cannot be confirmed until the seller completes the pickup address.')
            ->assertDontSeeText('Add a complete delivery address to check availability.')
            ->assertDontSee('data-delivery-available=', false);
    }
}
