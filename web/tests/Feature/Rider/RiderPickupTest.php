<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Payment;
use App\Models\Shared\StatusHistory;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IssuesLogisticsHandoff;

class RiderPickupTest extends RiderTestCase
{
    use IssuesLogisticsHandoff;

    public function test_pickup_list_shows_only_the_signed_in_riders_assignments(): void
    {
        $mine = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $theirs = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);

        $response = $this->actingAsRider()->get(route('rider.pickups.index'))->assertOk();

        $response->assertSee('Parcel #'.$mine->id);
        $response->assertDontSee('Parcel #'.$theirs->id);
    }

    public function test_confirming_a_pickup_advances_the_parcel_and_records_the_rider_as_actor(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))
            ->assertRedirect()
            ->assertSessionHas('success');

        $parcel = $parcel->fresh();

        $this->assertSame(OrderStatus::PickedUp, $parcel->status);
        $this->assertNotNull($parcel->handover_at);
        $this->assertNotNull($parcel->tracking_number);
        $this->assertStringStartsWith('TRK-', $parcel->tracking_number);

        $history = StatusHistory::query()
            ->where('seller_order_id', $parcel->id)
            ->where('to_status', OrderStatus::PickedUp->value)
            ->first();

        $this->assertNotNull($history);
        $this->assertSame(OrderActor::Rider->value, $history->actor_type);
        $this->assertSame($this->courier->id, (int) $history->actor_id);

        Notification::assertSentTo(
            $this->provider,
            RiderParcelActivity::class,
            fn (RiderParcelActivity $notification): bool => $notification->event === RiderParcelActivity::PICKUP,
        );
    }

    public function test_duplicate_pickup_confirmation_is_idempotent(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $this->actingAsRider()->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))->assertRedirect();

        $historyCount = StatusHistory::query()->where('seller_order_id', $parcel->id)->count();

        $this->actingAsRider()->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))->assertRedirect();

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertSame($historyCount, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_a_rider_cannot_confirm_another_riders_pickup(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);

        $this->actingAsRider()->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))->assertForbidden();

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_a_rider_cannot_confirm_a_pickup_outside_their_station_coverage(): void
    {
        $cebuSeller = Seller::factory()->create([
            'status' => 'approved',
            'municipality_name' => 'Cebu City',
            'municipality_code' => '072217000',
        ]);

        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'seller_id' => $cebuSeller->id,
            'courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))
            ->assertRedirect()
            ->assertSessionHasErrors('parcel');

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_pickup_never_collects_cod(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $payment = Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'amount_minor' => 150000,
        ]);

        $this->actingAsRider()->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))->assertRedirect();

        $payment = $payment->fresh();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->collected_by_id);
        $this->assertNull($payment->collected_amount_minor);
    }

    public function test_logistics_must_confirm_receipt_at_the_station_before_sorting(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $this->actingAsRider()->post(route('rider.pickups.confirm', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP))->assertRedirect();

        // The rider has no route that advances PickedUp → AtSortingCenter.
        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::PickedUp, $parcel->status);
        $this->assertSame(1, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());

        // Logistics confirms physical receipt at the origin Station Hub.
        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_logistics_can_reserve_a_pickup_rider_without_advancing_the_parcel(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $this->courier->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $parcel = $parcel->fresh();

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame($this->courier->id, (int) $parcel->courier_id);
        $this->assertSame(0, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());

        // The reserved rider now sees it and can confirm the collection.
        $this->actingAsRider()->get(route('rider.pickups.index'))->assertSee('Parcel #'.$parcel->id);
    }

    public function test_a_suspended_rider_loses_access_immediately(): void
    {
        $this->actingAsRider()->get(route('rider.pickups.index'))->assertOk();

        $this->rider->forceFill(['status' => Rider::STATUS_SUSPENDED])->save();

        $this->get(route('rider.pickups.index'))->assertRedirect(route('home', ['login' => 1]));
    }
}
