<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\WaybillQrCode;

/**
 * F2 Stage 2/3 — Rider tracking verification + scan-required handoffs.
 */
class ParcelScanTest extends RiderTestCase
{
    public function test_scan_page_renders_for_operational_rider(): void
    {
        $this->withoutVite();

        $this->actingAsRider()
            ->get(route('rider.scan.index'))
            ->assertOk()
            ->assertSee('data-parcel-scanner', false)
            ->assertSee(route('rider.scan.verify'), false)
            ->assertSee('Start camera', false)
            ->assertSee('Tracking number', false);
    }

    public function test_guest_cannot_verify_tracking(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $this->postJson(route('rider.scan.verify'), [
            'tracking_number' => $parcel->tracking_number,
        ])->assertUnauthorized();
    }

    public function test_rider_can_verify_assigned_pickup_without_changing_status(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);
        $historiesBefore = StatusHistory::query()->where('seller_order_id', $parcel->id)->count();

        $response = $this->actingAsRider()
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('parcel.tracking_number', $parcel->tracking_number)
            ->assertJsonPath('parcel.status', OrderStatus::ReadyForPickup->value)
            ->assertJsonPath('context', 'pickup');

        $actions = collect($response->json('actions'));
        $confirm = $actions->firstWhere('key', 'confirm_pickup');
        $this->assertNotNull($confirm);
        $this->assertNotEmpty($confirm['verification_token']);
        $this->assertSame(route('rider.pickups.confirm', $parcel), $confirm['url']);

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
        $this->assertSame(
            $historiesBefore,
            StatusHistory::query()->where('seller_order_id', $parcel->id)->count(),
        );
    }

    public function test_confirm_pickup_blocked_without_verification(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel))
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_manual_verify_then_confirm_advances_status(): void
    {
        $tracking = 'TRK-20261021-SCAN01';
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ], ['tracking_number' => $tracking]);

        $payload = (new WaybillQrCode)->payload($parcel->tracking_number);

        $token = $this->actingAsRider()
            ->postJson(route('rider.scan.verify'), ['tracking_number' => $payload])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel), [
                'verification_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_replayed_verification_token_is_rejected(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP);

        app(ParcelHandoffVerification::class)->consumeForRider(
            $this->rider,
            $parcel,
            ParcelHandoffVerification::ACTION_CONFIRM_PICKUP,
            $token,
        );

        $this->actingAsRider()
            ->from(route('rider.scan.index'))
            ->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])
            ->assertRedirect(route('rider.scan.index'))
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_cross_rider_cannot_use_another_riders_verification(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $otherRider = $otherCourier->rider;
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP, $otherRider);

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_wrong_parcel_verification_is_rejected(): void
    {
        $parcelA = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $parcelB = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $token = $this->handoffToken($parcelA, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP);

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcelB), ['verification_token' => $token])
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::ReadyForPickup, $parcelB->fresh()->status);
    }

    public function test_expired_verification_is_rejected(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP);
        $this->travel(11)->minutes();

        $this->actingAsRider()
            ->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');
    }

    public function test_unknown_tracking_is_rejected(): void
    {
        $this->actingAsRider()
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => 'TRK-99999999-NOPE00',
            ])
            ->assertNotFound();
    }

    public function test_wrong_rider_cannot_verify_another_assignment(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $otherCourier->id,
        ]);

        $this->actingAsRider()
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->assertForbidden();
    }

    public function test_delivery_start_requires_verification_token(): void
    {
        $parcel = $this->parcel(OrderStatus::AssignedToRider, [], [
            'delivery_courier_id' => $this->courier->id,
            'courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.start', $parcel))
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }
}
