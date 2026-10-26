<?php

namespace Tests\Feature\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Models\Payment;
use App\Models\StatusHistory;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Services\RiderHandoffVerification;
use Illuminate\Support\Facades\Notification;

class RiderDeliveryTest extends RiderTestCase
{
    public function test_delivery_list_shows_only_the_signed_in_riders_assignments(): void
    {
        $mine = $this->parcel(OrderStatus::AssignedToRider, [
            'delivery_courier_id' => $this->courier->id,
        ]);

        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $theirs = $this->parcel(OrderStatus::AssignedToRider, [
            'delivery_courier_id' => $otherCourier->id,
        ]);

        $response = $this->actingAsRider()->get(route('rider.deliveries.index'))->assertOk();

        $response->assertSee('Parcel #'.$mine->id);
        $response->assertDontSee('Parcel #'.$theirs->id);
    }

    public function test_delivery_list_shows_the_buyer_address_and_the_cod_amount_to_collect(): void
    {
        $parcel = $this->parcel(OrderStatus::OutForDelivery, [
            'delivery_courier_id' => $this->courier->id,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'amount_minor' => 150000,
        ]);

        $this->actingAsRider()->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertSee('123 Mabini Street')
            ->assertSee('Collect ₱1,500.00');
    }

    public function test_rider_can_start_an_assigned_delivery(): void
    {
        $parcel = $this->parcel(
            OrderStatus::AssignedToRider,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $this->actingAsRider()
            ->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);

        Notification::assertSentTo(
            $this->provider,
            RiderParcelActivity::class,
            fn (RiderParcelActivity $notification): bool => $notification->event === RiderParcelActivity::OUT_FOR_DELIVERY,
        );
    }

    public function test_starting_a_delivery_is_idempotent(): void
    {
        $parcel = $this->parcel(
            OrderStatus::AssignedToRider,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $this->actingAsRider()->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))->assertRedirect();
        $historyCount = StatusHistory::query()->where('seller_order_id', $parcel->id)->count();

        $this->actingAsRider()->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))->assertRedirect();

        $this->assertSame($historyCount, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_a_rider_cannot_start_a_delivery_routed_to_another_station_hub(): void
    {
        $otherArea = $this->otherStationArea();

        $parcel = $this->parcel(
            OrderStatus::AssignedToRider,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $otherArea->id],
        );

        $this->actingAsRider()
            ->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))
            ->assertRedirect()
            ->assertSessionHasErrors('parcel');

        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }

    public function test_a_rider_cannot_start_another_riders_delivery(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');

        $parcel = $this->parcel(OrderStatus::AssignedToRider, [
            'delivery_courier_id' => $otherCourier->id,
        ]);

        $this->actingAsRider()->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))->assertForbidden();

        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }

    public function test_a_rider_cannot_start_a_parcel_that_has_not_been_assigned_for_delivery(): void
    {
        $parcel = $this->parcel(OrderStatus::AtSortingCenter, [
            'delivery_courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.start', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_START_DELIVERY))
            ->assertRedirect()
            ->assertSessionHasErrors('parcel');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_rider_can_report_a_failed_delivery_with_a_reason_and_attempt_count(): void
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $this->actingAsRider()
            ->post(route('rider.deliveries.failed', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_FAIL_DELIVERY, [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
                'failure_notes' => 'Nobody answered the door.',
            ]))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $parcel = $parcel->fresh();

        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->status);
        $this->assertSame(1, (int) $parcel->delivery_attempts);
        $this->assertSame(
            DeliveryFailureReason::RecipientUnavailable->value,
            $parcel->delivery_failure_reason,
        );

        $history = StatusHistory::query()
            ->where('seller_order_id', $parcel->id)
            ->where('to_status', OrderStatus::DeliveryFailed->value)
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($this->courier->id, (int) $history->actor_id);

        Notification::assertSentTo(
            $this->provider,
            RiderParcelActivity::class,
            fn (RiderParcelActivity $notification): bool => $notification->event === RiderParcelActivity::DELIVERY_FAILED,
        );
    }

    public function test_duplicate_failed_delivery_report_is_idempotent(): void
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $payload = [
            'failure_reason' => DeliveryFailureReason::WrongAddress->value,
            'failure_notes' => 'Address does not exist.',
        ];

        $this->actingAsRider()->post(route('rider.deliveries.failed', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_FAIL_DELIVERY, $payload))->assertRedirect();
        $this->actingAsRider()->post(route('rider.deliveries.failed', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_FAIL_DELIVERY, $payload))->assertRedirect();

        $this->assertSame(1, (int) $parcel->fresh()->delivery_attempts);
        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::DeliveryFailed->value)
                ->count(),
        );
    }

    public function test_other_requires_notes(): void
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $this->actingAsRider()
            ->post(route('rider.deliveries.failed', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_FAIL_DELIVERY, [
                'failure_reason' => DeliveryFailureReason::Other->value,
            ]))
            ->assertSessionHasErrors('failure_notes');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
    }

    public function test_a_rider_cannot_report_a_failure_for_another_riders_parcel(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');

        $parcel = $this->parcel(OrderStatus::OutForDelivery, [
            'delivery_courier_id' => $otherCourier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.failed', $parcel), $this->withHandoff($parcel, RiderHandoffVerification::ACTION_FAIL_DELIVERY, [
                'failure_reason' => DeliveryFailureReason::RecipientUnavailable->value,
            ]))
            ->assertForbidden();
    }
}
