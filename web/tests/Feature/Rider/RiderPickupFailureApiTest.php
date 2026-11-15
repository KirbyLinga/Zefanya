<?php

namespace Tests\Feature\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Admin\PickupFailureException;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Notifications\Rider\PickupFailure;
use App\Notifications\Seller\PickupFailed;
use Illuminate\Support\Facades\Notification;

/**
 * POST /api/rider/pickups/{sellerOrder}/failed — the bearer-token twin of the
 * Rider web pickup-failure action. Both run PickupFailureService, so these
 * tests focus on the API contract: ownership, the two-attempt rule, the
 * duplicate guard (`expected_attempt`), 409 for stale state and the server
 * deciding cancellation.
 */
class RiderPickupFailureApiTest extends RiderTestCase
{
    private function api(?Rider $rider = null): static
    {
        $this->app['auth']->forgetGuards();
        $token = ($rider ?? $this->rider)->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function readyParcel(): SellerOrder
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $parcel->forceFill(['carrier_provider_id' => $this->provider->id])->save();

        return $parcel->fresh();
    }

    private function report(SellerOrder $parcel, int $expectedAttempt, array $extra = [])
    {
        return $this->api()->postJson("/api/rider/pickups/{$parcel->id}/failed", [
            'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            'expected_attempt' => $expectedAttempt,
            ...$extra,
        ]);
    }

    public function test_jobs_list_exposes_pickup_attempts_and_the_server_reasons(): void
    {
        $parcel = $this->readyParcel();
        $parcel->pickupAttempts()->create(['attempt_number' => 1, 'rider_id' => $this->rider->id, 'failure_reason' => 'other', 'failed_at' => now()]);

        $this->api()->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonPath('data.pickups.0.id', $parcel->id)
            ->assertJsonPath('data.pickups.0.pickup_attempts', 1)
            ->assertJsonPath('data.pickup_failure.max_attempts', 2)
            ->assertJsonCount(count(DeliveryFailureReason::cases()), 'data.pickup_failure.reasons')
            ->assertJsonPath('data.pickup_failure.reasons.0.value', DeliveryFailureReason::RecipientUnavailable->value);
    }

    public function test_first_failure_keeps_the_parcel_ready_releases_the_rider_and_notifies(): void
    {
        $parcel = $this->readyParcel();

        $this->report($parcel, 1)->assertOk()
            ->assertJsonPath('data.attempt', 1)
            ->assertJsonPath('data.attempts_remaining', 1)
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.parcel.status', OrderStatus::ReadyForPickup->value)
            ->assertJsonPath('data.parcel.pickup_attempts', 1);

        $parcel->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame(1, $parcel->pickupAttempts()->count());
        $this->assertSame($this->courier->id, (int) $parcel->courier_id);
        $this->assertTrue((bool) $this->courier->fresh()->is_available);
        $this->assertSame(0, InventoryTransaction::query()->where('seller_order_id', $parcel->id)->count());

        Notification::assertSentTo($this->seller, PickupFailed::class);
        Notification::assertSentTo($this->provider, RiderParcelActivity::class);
        Notification::assertNotSentTo($this->rider, PickupFailure::class);
    }

    public function test_a_retried_first_report_is_a_409_and_never_becomes_the_cancelling_second(): void
    {
        $parcel = $this->readyParcel();

        $this->report($parcel, 1)->assertOk();
        // Lost response, the app retries the same report: refused, nothing changes.
        $this->report($parcel, 1)->assertStatus(409)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'already recorded'));

        $parcel->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame(1, $parcel->pickupAttempts()->count());
    }

    public function test_second_failure_cancels_through_the_status_service_and_restores_stock_once(): void
    {
        config(['admin.notification_email' => 'ops@example.com']);
        $parcel = $this->readyParcel();
        $item = $parcel->orderItems()->firstOrFail();
        $variant = $item->product->variants()->firstOrFail();
        $item->forceFill(['variant_id' => $variant->id, 'quantity' => 2])->save();
        $variant->forceFill(['stock_quantity' => 10])->save();
        $payment = Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'amount_minor' => 20000,
            'status' => PaymentStatus::Pending->value,
        ]);

        $this->report($parcel, 1)->assertOk();
        $this->report($parcel, 2, ['failure_reason' => DeliveryFailureReason::Other->value, 'failure_notes' => 'Closed again.'])
            ->assertOk()
            ->assertJsonPath('data.attempt', 2)
            ->assertJsonPath('data.attempts_remaining', 0)
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.parcel.status', OrderStatus::Cancelled->value);

        $parcel->refresh();
        $this->assertSame(OrderStatus::Cancelled, $parcel->status);
        $this->assertSame(12, (int) $variant->fresh()->stock_quantity);
        $this->assertNotSame(PaymentStatus::Pending, $payment->fresh()->status);
        $history = StatusHistory::query()->where('seller_order_id', $parcel->id)->sole();
        $this->assertSame('api', $history->source);

        Notification::assertSentTo($this->rider, PickupFailure::class);
        Notification::assertSentTo($this->seller, PickupFailed::class);
        Notification::assertSentOnDemand(PickupFailureException::class);

        // A third report changes nothing and restores no stock.
        $this->report($parcel, 3)->assertStatus(422);
        $this->report($parcel, 2)->assertStatus(409);
        $this->assertSame(2, $parcel->pickupAttempts()->count());
        $this->assertSame(12, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_a_parcel_no_longer_waiting_for_pickup_is_a_409_with_no_attempt(): void
    {
        $parcel = $this->readyParcel();
        $parcel->forceFill(['status' => OrderStatus::PickedUp->value])->save();

        $this->report($parcel, 1)->assertStatus(409);

        $this->assertSame(0, $parcel->pickupAttempts()->count());
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        Notification::assertNothingSentTo($this->seller);
    }

    public function test_another_riders_pickup_is_forbidden_and_unchanged(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);

        $this->report($parcel, 1)->assertForbidden();

        $this->assertSame(0, $parcel->pickupAttempts()->count());
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_validation_requires_a_known_reason_and_the_expected_attempt(): void
    {
        $parcel = $this->readyParcel();

        $this->api()->postJson("/api/rider/pickups/{$parcel->id}/failed", ['failure_reason' => 'invented', 'expected_attempt' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('failure_reason');
        $this->api()->postJson("/api/rider/pickups/{$parcel->id}/failed", ['failure_reason' => 'other'])
            ->assertStatus(422)->assertJsonValidationErrors('expected_attempt');

        $this->assertSame(0, $parcel->pickupAttempts()->count());
    }

    public function test_requires_a_rider_bearer_token(): void
    {
        $parcel = $this->readyParcel();

        $this->postJson("/api/rider/pickups/{$parcel->id}/failed", ['failure_reason' => 'other', 'expected_attempt' => 1])
            ->assertUnauthorized();

        $buyerToken = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->postJson("/api/rider/pickups/{$parcel->id}/failed", ['failure_reason' => 'other', 'expected_attempt' => 1])
            ->assertForbidden();

        $this->assertSame(0, $parcel->pickupAttempts()->count());
    }
}
