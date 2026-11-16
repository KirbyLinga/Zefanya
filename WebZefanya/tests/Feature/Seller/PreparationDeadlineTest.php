<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Seller\PreparationOverdue;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** I-07 — preparation deadline, one-time overdue escalation, no cancel after pickup. */
class PreparationDeadlineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function parcel(OrderStatus $status, array $extra = []): SellerOrder
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = Order::factory()->create(['status' => OrderStatus::Placed]);

        return SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'status' => $status,
        ] + $extra);
    }

    public function test_confirming_sets_the_configured_deadline(): void
    {
        config(['orders.preparation_deadline_hours' => 24]);
        $parcel = $this->parcel(OrderStatus::Placed);

        $updated = (new SellerOrderStatusService)->transition(
            $parcel, OrderStatus::Confirmed, null, 'seller', OrderActor::Seller, $parcel->seller_id,
        );

        $this->assertNotNull($updated->fresh()->preparation_due_at);
        $this->assertEqualsWithDelta(24, now()->diffInHours($updated->fresh()->preparation_due_at), 1);
    }

    public function test_overdue_order_notifies_seller_once_without_changing_status(): void
    {
        Notification::fake();
        $parcel = $this->parcel(OrderStatus::Confirmed, ['preparation_due_at' => now()->subHour()]);

        $this->artisan('orders:escalate-preparation-overdue')->assertSuccessful();
        $this->artisan('orders:escalate-preparation-overdue')->assertSuccessful();

        Notification::assertSentToTimes($parcel->seller, PreparationOverdue::class, 1);
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->assertNotNull($parcel->fresh()->preparation_overdue_notified_at);
    }

    public function test_orders_within_deadline_or_already_picked_up_are_not_escalated(): void
    {
        Notification::fake();
        $this->parcel(OrderStatus::Confirmed, ['preparation_due_at' => now()->addHour()]);
        $this->parcel(OrderStatus::PickedUp, ['preparation_due_at' => now()->subHour()]);

        $this->artisan('orders:escalate-preparation-overdue')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_seller_cannot_cancel_after_physical_pickup(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);

        $this->expectException(\InvalidArgumentException::class);
        (new SellerOrderStatusService)->transition(
            $parcel, OrderStatus::Cancelled, null, 'seller', OrderActor::Seller, $parcel->seller_id,
        );
    }
}
