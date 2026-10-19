<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Z-005: a PICKUP courier collects the parcel at the seller's shop, so the
 * service-area match must use the SELLER's municipality. Matching the buyer's
 * destination municipality sends a rider from the wrong city (or nobody,
 * when no rider serves the destination but one does serve the shop).
 */
class CourierPickupMunicipalityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pickup_matches_the_sellers_municipality_not_the_buyers(): void
    {
        $seller = Seller::factory()->create([
            'province_name' => 'Laguna',
            'municipality_name' => 'Los Baños',
        ]);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'weight_grams' => 2000,
        ]);

        // The buyer lives somewhere else entirely — that is the destination,
        // not the pickup point.
        $order = Order::factory()->create([
            'buyer_id' => Buyer::factory()->create(['municipality_name' => 'Quezon City'])->id,
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);

        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $sellerOrder = (new SellerOrderStatusService)
            ->transition($sellerOrder->fresh(), OrderStatus::ReadyForPickup)
            ->fresh();

        // The only available rider serves the SELLER's municipality.
        $pickupCourier = Courier::factory()->create([
            'service_area' => ['Los Baños'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $assigned = (new CourierAssignmentService)->assignPickup($sellerOrder);

        $this->assertNotNull($assigned, 'A rider serving the seller municipality should collect the parcel.');
        $this->assertSame($pickupCourier->id, $assigned->id);
    }
}
