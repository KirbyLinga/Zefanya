<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/courier/jobs` — the parcels assigned to the signed-in courier.
 *
 * The things worth proving: scoping (a token cannot see another courier's
 * jobs), the shape the app's `DeliveryJob` reads, finished statuses excluded,
 * and a buyer token refused.
 */
class CourierJobApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function courier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'street' => '18 Rizal Street',
            'municipality_name' => 'Pila',
            'province_name' => 'Laguna',
        ]);
    }

    /**
     * A seller_order assigned to [courier], in the given status.
     */
    private function assignJob(
        LogisticsProvider $courier,
        string $status = 'PLACED',
        float $fee = 85.00,
    ): SellerOrder {
        $seller = Seller::factory()->create([
            'business_name' => 'Willow & Bloom Atelier',
            'status' => 'approved',
            'street' => '12 Mabini Street',
            'barangay_name' => 'Lahug',
            'municipality_name' => 'Cebu City',
            'province_name' => 'Cebu',
        ]);

        $buyer = Buyer::factory()->create();

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => [
                'name' => 'Marites Santos',
                'full' => '42 Maginhawa Street, Pinyahan, Quezon City',
            ],
            'subtotal_minor' => 129900,
            'discount_minor' => 0,
            'total_minor' => 129900,
        ]);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Linen Blend Wrap Dress',
            'status' => 'active',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 129900,
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => (int) round($fee * 100),
            'delivery_area' => 'Quezon City (8.4 km)',
        ]);
        // status is not mass-assignable — pipeline transitions go through
        // controlled business logic in production. Tests set it directly.
        $sellerOrder->forceFill(['status' => $status])->save();

        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 129900,
        ]);

        return $sellerOrder;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_it_requires_a_token(): void
    {
        $this->getJson(route('api.courier.jobs.index'))->assertUnauthorized();
    }

    public function test_a_buyer_token_is_refused(): void
    {
        Sanctum::actingAs(Buyer::factory()->create());

        $this->getJson(route('api.courier.jobs.index'))->assertForbidden();
    }

    public function test_it_returns_empty_when_no_jobs_assigned(): void
    {
        Sanctum::actingAs($this->courier());

        $this->getJson(route('api.courier.jobs.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_returns_the_shape_the_apps_delivery_job_reads(): void
    {
        $courier = $this->courier();
        $this->assignJob($courier, fee: 85.00);

        Sanctum::actingAs($courier);

        $response = $this->getJson(route('api.courier.jobs.index'))->assertOk();

        $response->assertJsonStructure([
            'data' => [
                ['id', 'order_id', 'shop_name', 'pickup_address',
                 'buyer_name', 'delivery_area', 'package_size', 'fee'],
            ],
        ]);

        // String id, like every other id the app reads.
        $this->assertIsString($response->json('data.0.id'));
        $this->assertIsString($response->json('data.0.order_id'));

        $this->assertSame('Willow & Bloom Atelier', $response->json('data.0.shop_name'));
        $this->assertStringContainsString('Cebu', $response->json('data.0.pickup_address'));
        $this->assertSame('Marites Santos', $response->json('data.0.buyer_name'));
        $this->assertSame('Quezon City (8.4 km)', $response->json('data.0.delivery_area'));
        $this->assertEqualsWithDelta(85.0, $response->json('data.0.fee'), 0.001);
        $this->assertStringContainsString('1 item', $response->json('data.0.package_size'));
    }

    public function test_it_returns_only_this_couriers_jobs(): void
    {
        $me = $this->courier();
        $other = $this->courier();

        $this->assignJob($me);
        $this->assignJob($other);

        Sanctum::actingAs($me);

        $this->getJson(route('api.courier.jobs.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_finished_jobs_are_not_listed(): void
    {
        $courier = $this->courier();

        // Active — should appear.
        $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        // Finished — should not appear.
        $this->assignJob($courier, 'DELIVERED');
        $this->assignJob($courier, 'COMPLETED');
        $this->assignJob($courier, 'RETURNED');

        Sanctum::actingAs($courier);

        $this->getJson(route('api.courier.jobs.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_all_active_pipeline_statuses_appear(): void
    {
        $courier = $this->courier();

        $active = [
            'PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP',
            'PICKED_UP', 'AT_SORTING_CENTER', 'SORTED',
            'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED',
        ];

        foreach ($active as $status) {
            $this->assignJob($courier, $status);
        }

        Sanctum::actingAs($courier);

        $this->getJson(route('api.courier.jobs.index'))
            ->assertOk()
            ->assertJsonCount(count($active), 'data');
    }
}
