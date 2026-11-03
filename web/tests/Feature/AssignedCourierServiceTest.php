<?php

namespace Tests\Feature;

use App\Exceptions\DeliveryHandoverException;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\OrderDeliveryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mutation f missing test: the service-level assigned-courier check.
 *
 * The HTTP-level test (test_a_different_courier_cannot_confirm) exercises the
 * controller's abort_unless guard. This test calls OrderDeliveryService
 * directly, bypassing the controller, so removing the service's own check
 * is caught independently.
 */
class AssignedCourierServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_service_rejects_a_courier_not_assigned_to_the_job(): void
    {
        Storage::fake('local');

        $buyer = Buyer::factory()->create();
        $courier = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $intruder = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'name' => 'Service Test Parcel',
        ]);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['name' => $buyer->fullName(), 'full' => 'Test address'],
            'subtotal_minor' => 50000,
            'discount_minor' => 0,
            'total_minor' => 50000,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 50000,
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => 8500,
            'delivery_area' => 'Test area',
        ]);
        $sellerOrder->forceFill(['status' => 'OUT_FOR_DELIVERY'])->save();

        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        $service = app(OrderDeliveryService::class);

        $pass = $service->issuePass($sellerOrder, $buyer);

        $this->expectException(DeliveryHandoverException::class);

        $service->markDelivered(
            $sellerOrder,
            $intruder,
            ['delivery_otp' => $pass['code']],
            UploadedFile::fake()->create('doorstep.jpg', 100, 'image/jpeg'),
        );
    }
}
