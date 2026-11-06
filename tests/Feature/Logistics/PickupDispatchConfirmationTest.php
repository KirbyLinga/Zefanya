<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Rider\PickupAssigned;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * The pickup workflow: automatic routing reserves and notifies a Rider when
 * the parcel becomes ready; Logistics can manually reserve/reassign instead,
 * and the Rider confirms collection with F2.
 */
class PickupDispatchConfirmationTest extends TestCase
{
    use CreatesAssignableFleet;
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $first;

    private Courier $second;

    private Seller $seller;

    private SellerOrder $parcel;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->provider = LogisticsProvider::factory()->create();
        $this->first = $this->assignableCourier($this->provider, ['max_weight_grams' => 20000], 'San Fernando', '030540100');
        $this->second = $this->assignableCourier($this->provider, ['max_weight_grams' => 20000], 'San Fernando', '030540100');
        $this->seller = Seller::factory()->create(['barangay_code' => '0305401001']);
        $this->parcel = SellerOrder::factory()->create([
            'seller_id' => $this->seller->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001', 'municipality' => 'San Fernando']])->id,
            'status' => OrderStatus::ReadyForPickup->value,
            'carrier_provider_id' => $this->provider->id,
            'origin_station_hub_id' => $this->first->rider->station_hub_id,
        ]);
    }

    public function test_seller_marking_ready_reserves_and_notifies_a_pickup_rider(): void
    {
        $preparing = $this->parcel->fresh();
        $preparing->forceFill(['status' => OrderStatus::Preparing])->save();

        app(SellerOrderStatusService::class)->transition(
            $preparing,
            OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller,
            actorId: $this->seller->id,
        );

        $parcel = $preparing->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame($this->first->id, (int) $parcel->courier_id);
        $this->assertSame(1, $parcel->deliveryAssignments()->where('role', 'pickup')->count());
        $this->assertFalse($this->first->fresh()->is_available);
        Notification::assertSentToTimes($this->first->rider, PickupAssigned::class, 1);
    }

    public function test_incoming_parcels_keeps_selected_carrier_parcels_visible_without_current_origin_coverage(): void
    {
        $this->seller->forceFill(['barangay_code' => null])->save();

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertViewHas('shipments', fn ($shipments) => $shipments->contains('id', $this->parcel->id));
    }

    public function test_manual_reassignment_after_automatic_dispatch_is_preserved_by_automatic_retry(): void
    {
        $this->parcel->forceFill(['status' => OrderStatus::Preparing])->save();
        app(SellerOrderStatusService::class)->transition(
            $this->parcel,
            OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller,
            actorId: $this->seller->id,
        );
        Notification::assertSentToTimes($this->first->rider, PickupAssigned::class, 1);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), [
                'reassign' => 1,
                'courier_id' => $this->second->id,
                'expected_courier_id' => $this->first->id,
                'reason' => 'Original Rider cannot collect today.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->post(route('logistics.dispatch.retry', [$this->parcel, 'pickup']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $parcel = $this->parcel->fresh();
        $this->assertSame($this->second->id, (int) $parcel->courier_id);
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertNull($parcel->handover_at);
        $this->assertSame(2, $parcel->deliveryAssignments()->where('role', 'pickup')->count());
        Notification::assertNotSentTo($this->second->rider, PickupAssigned::class);

        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentToTimes($this->first->rider, PickupAssigned::class, 1);
        Notification::assertSentToTimes($this->second->rider, PickupAssigned::class, 1);
    }

    public function test_incoming_parcels_does_not_show_another_carriers_parcel_even_with_origin_coverage(): void
    {
        $otherProvider = LogisticsProvider::factory()->create();
        $this->parcel->forceFill(['carrier_provider_id' => $otherProvider->id])->save();

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertViewHas('shipments', fn ($shipments) => ! $shipments->contains('id', $this->parcel->id));
    }

    public function test_assign_rider_reserves_without_notifying_the_rider(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), ['courier_id' => $this->first->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $parcel = $this->parcel->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertSame($this->first->id, (int) $parcel->courier_id);
        $this->assertSame(1, $parcel->deliveryAssignments()->where('role', 'pickup')->count());

        Notification::assertNotSentTo($this->first->rider, PickupAssigned::class);
        Notification::assertNotSentTo($this->second->rider, PickupAssigned::class);
    }

    public function test_busy_rider_within_weight_limit_is_still_offered_for_manual_pickup_assignment(): void
    {
        $this->first->forceFill(['is_available' => false])->save();

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertViewHas('availableCouriers', fn ($couriers): bool => $couriers->contains(
                fn ($courier): bool => (int) $courier->id === (int) $this->first->id,
            ));

        $this->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), ['courier_id' => $this->first->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($this->first->id, (int) $this->parcel->fresh()->courier_id);
    }

    public function test_assign_rider_requires_an_explicit_selection(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel))
            ->assertRedirect()
            ->assertSessionHasErrors('courier_id');

        $parcel = $this->parcel->fresh();
        $this->assertNull($parcel->courier_id);
        $this->assertSame(0, $parcel->deliveryAssignments()->count());
        $this->assertTrue($this->first->fresh()->is_available);
        $this->assertTrue($this->second->fresh()->is_available);
        Notification::assertNothingSent();
    }

    public function test_assign_dropdown_lists_only_riders_of_the_parcel_station_hub(): void
    {
        $foreignRider = Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
            'first_name' => 'Foreign',
            'last_name' => 'Stationrider',
            'station_hub_id' => LogisticsHub::query()->create([
                'logistics_provider_id' => $this->provider->id,
                'parent_hub_id' => $this->first->rider->station->parent_hub_id,
                'name' => 'Foreign Station Hub',
                'hub_type' => LogisticsHub::STATION,
                'region_code' => '030000000',
                'region_name' => 'Region III',
                'province_code' => '030540000',
                'province_name' => 'Pampanga',
                'municipality' => 'San Fernando',
                'city_municipality_code' => '030540100',
                'is_active' => true,
                'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            ])->id,
        ]);
        Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'rider_id' => $foreignRider->id,
            'area_id' => $this->first->area_id,
            'is_available' => true,
            'max_weight_grams' => 20000,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('Select an eligible Rider')
            ->assertSee($this->first->rider->fullName())
            ->assertDontSee($foreignRider->fullName());
    }

    public function test_confirm_dispatch_notifies_the_selected_rider_exactly_once(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), ['courier_id' => $this->first->id])
            ->assertSessionHas('success');

        $courier = $this->parcel->fresh()->courier;

        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo(
            $courier->rider,
            PickupAssigned::class,
            fn (PickupAssigned $notification): bool => $notification->sellerOrder->is($this->parcel)
                && $notification->assignmentId > 0,
        );

        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentToTimes($courier->rider, PickupAssigned::class, 1);
        $this->assertSame(OrderStatus::ReadyForPickup, $this->parcel->fresh()->status);
        $this->assertSame(1, $this->parcel->fresh()->deliveryAssignments()->where('role', 'pickup')->count());
    }

    public function test_confirm_dispatch_after_reassignment_notifies_the_new_rider_once(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), ['courier_id' => $this->first->id])
            ->assertSessionHas('success');
        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel));

        $original = $this->parcel->fresh()->courier;
        $replacement = $original->is($this->first) ? $this->second : $this->first;

        $this->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), [
            'reassign' => 1,
            'courier_id' => $replacement->id,
            'expected_courier_id' => $original->id,
            'reason' => 'Original Rider cannot collect today.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($replacement->id, (int) $this->parcel->fresh()->courier_id);
        Notification::assertNotSentTo($replacement->rider, PickupAssigned::class);

        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect();

        Notification::assertSentToTimes($replacement->rider, PickupAssigned::class, 1);
        Notification::assertSentToTimes($original->rider, PickupAssigned::class, 1);
        $this->assertSame(2, $this->parcel->fresh()->deliveryAssignments()->where('role', 'pickup')->count());
    }

    public function test_foreign_provider_cannot_confirm_the_dispatch_or_notify_the_rider(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $this->parcel), ['courier_id' => $this->first->id])
            ->assertSessionHas('success');
        $courier = $this->parcel->fresh()->courier;

        $other = LogisticsProvider::factory()->create();
        $this->actingAs($other, 'logistics')
            ->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertForbidden();

        Notification::assertNotSentTo($courier->rider, PickupAssigned::class);
        $this->assertSame(0, $courier->rider->notifications()->count());
    }

    public function test_confirm_dispatch_requires_a_reserved_rider(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.confirmPickupDispatch', $this->parcel))
            ->assertRedirect()
            ->assertSessionHas('error');

        Notification::assertNothingSent();
        $this->assertSame(0, $this->parcel->deliveryAssignments()->count());
    }
}
