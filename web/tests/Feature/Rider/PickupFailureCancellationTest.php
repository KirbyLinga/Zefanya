<?php

namespace Tests\Feature\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\StatusHistory;
use App\Notifications\Admin\PickupFailureException;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Notifications\Rider\PickupFailure;
use App\Notifications\Seller\PickupFailed;
use Illuminate\Support\Facades\Notification;

class PickupFailureCancellationTest extends RiderTestCase
{
    public function test_first_pickup_failure_keeps_the_parcel_ready_and_releases_the_rider(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $parcel->forceFill(['carrier_provider_id' => $this->provider->id])->save();

        $this->actingAsRider()
            ->post(route('rider.pickups.reportFailure', $parcel), [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            ])
            ->assertRedirect(route('rider.pickups.index'))
            ->assertSessionHas('status');

        $parcel->refresh();

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame(1, $parcel->pickupAttempts()->count());
        // The rider keeps the assignment for the one allowed retry.
        $this->assertSame($this->courier->id, (int) $parcel->courier_id);
        $this->assertTrue((bool) $this->courier->fresh()->is_available);

        // No status change means no history row and no inventory movement.
        $this->assertSame(0, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
        $this->assertSame(0, InventoryTransaction::query()->where('seller_order_id', $parcel->id)->count());

        Notification::assertSentTo($this->seller, PickupFailed::class);
        Notification::assertSentTo($this->provider, RiderParcelActivity::class);
        Notification::assertNotSentTo($this->rider, PickupFailure::class);
    }

    public function test_second_pickup_failure_cancels_via_the_status_service(): void
    {
        config(['admin.notification_email' => 'ops@example.com']);

        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $parcel->forceFill(['carrier_provider_id' => $this->provider->id])->save();

        $item = $parcel->orderItems()->firstOrFail();
        $variant = $item->product->variants()->firstOrFail();
        $item->forceFill(['variant_id' => $variant->id, 'quantity' => 2])->save();
        $variant->forceFill(['stock_quantity' => 10])->save();

        $payment = Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'amount_minor' => 20000,
            'status' => PaymentStatus::Pending->value,
        ]);

        $post = fn () => $this->actingAsRider()
            ->post(route('rider.pickups.reportFailure', $parcel), [
                'failure_reason' => DeliveryFailureReason::Other->value,
                'failure_notes' => 'Shop was closed for the second time.',
            ]);

        $post()->assertRedirect(route('rider.pickups.index'));
        $post()
            ->assertRedirect(route('rider.pickups.index'))
            ->assertSessionHas('status', 'Order cancelled after second pickup failure. Inventory restored.');

        $parcel->refresh();
        $this->assertSame(OrderStatus::Cancelled, $parcel->status);
        $this->assertSame([1, 2], $parcel->pickupAttempts()->pluck('attempt_number')->all());

        // One audit row, written by the canonical service for the rider.
        $history = StatusHistory::query()->where('seller_order_id', $parcel->id)->sole();
        $this->assertSame(OrderStatus::ReadyForPickup, $history->from_status);
        $this->assertSame(OrderStatus::Cancelled, $history->to_status);
        $this->assertSame(OrderActor::Rider->value, $history->actor_type);
        $this->assertSame($this->courier->id, (int) $history->actor_id);
        $this->assertSame('web', $history->source);

        // Stock came back exactly once with a ledger row.
        $this->assertSame(12, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(12, (int) $item->product->fresh()->stock_quantity);

        $ledger = InventoryTransaction::query()
            ->where('seller_order_id', $parcel->id)
            ->where('reason', 'cancel')
            ->sole();
        $this->assertSame(2, (int) $ledger->delta);
        $this->assertSame(10, (int) $ledger->quantity_before);
        $this->assertSame(12, (int) $ledger->quantity_after);

        // Pending COD can never be collected for a cancelled parcel.
        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);

        // The parent order rolls up to Cancelled.
        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($parcel->order_id)->status);

        // The pickup courier is free again.
        $this->assertTrue((bool) $this->courier->fresh()->is_available);

        Notification::assertSentTo($this->rider, PickupFailure::class);
        Notification::assertSentTo($this->seller, PickupFailed::class);
        Notification::assertSentTo($this->provider, RiderParcelActivity::class);
        Notification::assertSentOnDemand(PickupFailureException::class);

        // A third report is refused: no third attempt, no second restoration.
        $post()->assertSessionHas('error');
        $this->assertSame(2, $parcel->pickupAttempts()->count());
        $this->assertSame(12, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_another_riders_parcel_cannot_be_reported(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);

        $this->actingAsRider()
            ->post(route('rider.pickups.reportFailure', $parcel), [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, $parcel->pickupAttempts()->count());
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_a_failed_cancellation_rolls_back_the_attempt(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $this->actingAsRider()
            ->post(route('rider.pickups.reportFailure', $parcel), [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            ])
            ->assertRedirect();

        // The parcel moved on (e.g. another tab confirmed the pickup), so the
        // second failure can no longer legally cancel it.
        $parcel->forceFill(['status' => OrderStatus::PickedUp->value])->save();

        $this->actingAsRider()
            ->post(route('rider.pickups.reportFailure', $parcel), [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            ])
            ->assertSessionHas('error');

        $parcel->refresh();
        $this->assertSame(OrderStatus::PickedUp, $parcel->status);
        // Attempt #2 was rolled back with the rejected cancellation.
        $this->assertSame(1, $parcel->pickupAttempts()->count());
        $this->assertSame(0, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
    }
}
