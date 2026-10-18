<?php

namespace Tests\Feature\Api;

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
 * Milestone endpoints:
 *   PATCH /api/courier/jobs/{sellerOrder}/milestone  - courier writes
 *   GET   /api/orders/{sellerOrder}/milestone        - buyer reads
 */
class MilestoneApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function courier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function assignJob(LogisticsProvider $courier, string $status = 'PLACED'): SellerOrder
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['full' => 'Test address'],
            'subtotal_minor' => 50000,
            'discount_minor' => 0,
            'total_minor' => 50000,
        ]);

        $so = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 50000,
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => 8500,
            'delivery_area' => 'Test area',
        ]);
        $so->forceFill(['status' => $status])->save();

        OrderItem::query()->create([
            'seller_order_id' => $so->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        return $so->fresh();
    }

    // -- PATCH /api/courier/jobs/{sellerOrder}/milestone

    public function test_courier_can_advance_milestone(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'PLACED');

        Sanctum::actingAs($courier);

        $response = $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'CONFIRMED'],
        )->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertSame('CONFIRMED', $response->json('milestone'));
        $this->assertSame('CONFIRMED', $job->fresh()->status->value);
    }

    public function test_milestone_requires_a_token(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier);

        $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'CONFIRMED'],
        )->assertUnauthorized();
    }

    public function test_buyer_token_cannot_advance_courier_milestone(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier);

        Sanctum::actingAs(Buyer::factory()->create());

        $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'CONFIRMED'],
        )->assertForbidden();
    }

    public function test_courier_cannot_advance_another_couriers_job(): void
    {
        $owner = $this->courier();
        $interloper = $this->courier();
        $job = $this->assignJob($owner, 'PLACED');

        Sanctum::actingAs($interloper);

        $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'CONFIRMED'],
        )->assertForbidden();
    }

    public function test_invalid_transition_is_refused(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'PLACED');

        Sanctum::actingAs($courier);

        $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'OUT_FOR_DELIVERY'],
        )->assertStatus(422);

        $this->assertSame('PLACED', $job->fresh()->status->value);
    }

    public function test_terminal_job_cannot_be_advanced(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'DELIVERED');

        Sanctum::actingAs($courier);

        $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            ['milestone' => 'COMPLETED'],
        )->assertStatus(422);
    }

    public function test_full_pickup_pipeline_step_by_step(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'PLACED');

        Sanctum::actingAs($courier);

        $pipeline = [
            'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP',
            'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER',
            'OUT_FOR_DELIVERY', 'DELIVERED',
        ];

        foreach ($pipeline as $next) {
            $this->patchJson(
                route('api.courier.jobs.milestone', $job),
                ['milestone' => $next],
            )->assertOk();
        }

        $this->assertSame('DELIVERED', $job->fresh()->status->value);
    }

    // -- GET /api/orders/{sellerOrder}/milestone

    public function test_buyer_can_read_milestone_for_their_parcel(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        $buyer = Buyer::query()->find($job->order->buyer_id);

        Sanctum::actingAs($buyer);

        $response = $this->getJson(
            route('api.orders.milestone', $job),
        )->assertOk();

        $this->assertSame('OUT_FOR_DELIVERY', $response->json('milestone'));
    }

    public function test_milestone_read_requires_a_token(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier);

        $this->getJson(route('api.orders.milestone', $job))->assertUnauthorized();
    }

    public function test_buyer_cannot_read_another_buyers_parcel_milestone(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier);
        $interloper = Buyer::factory()->create();

        Sanctum::actingAs($interloper);

        $this->getJson(route('api.orders.milestone', $job))->assertForbidden();
    }

    public function test_courier_token_cannot_read_buyer_milestone_endpoint(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier);

        Sanctum::actingAs($courier);

        $this->getJson(route('api.orders.milestone', $job))->assertForbidden();
    }
}
