<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Notifications\Rider\DeliveryAssigned;
use App\Notifications\Rider\PickupAssigned;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Rider\RiderRegistrationService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class AutomaticDispatchTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use CreatesAssignableFleet;
    use LazilyRefreshDatabase;

    private function fixture(OrderStatus $status = OrderStatus::ReadyForPickup): array
    {
        $provider = LogisticsProvider::factory()->create();
        $first = $this->assignableCourier($provider, ['max_weight_grams' => 20000], 'San Fernando', '030540100');
        $second = $this->assignableCourier($provider, ['max_weight_grams' => 10000], 'San Fernando', '030540100');
        $seller = Seller::factory()->create(['barangay_code' => '0305401001']);
        $parcel = SellerOrder::factory()->create([
            'seller_id' => $seller->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001', 'municipality' => 'San Fernando']])->id,
            'status' => $status->value, 'carrier_provider_id' => $provider->id,
            'origin_station_hub_id' => $first->rider->station_hub_id,
            'tracking_number' => $status === OrderStatus::ReadyForPickup || $status === OrderStatus::Preparing ? null : 'TRK-AUTO-'.bin2hex(random_bytes(4)),
        ]);

        return compact('provider', 'first', 'second', 'seller', 'parcel');
    }

    public function test_mark_ready_automatically_reserves_and_notifies_a_pickup_rider(): void
    {
        $f = $this->fixture(OrderStatus::Preparing);
        Notification::fake();
        app(SellerOrderStatusService::class)->transition($f['parcel'], OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller, actorId: $f['seller']->id);
        $parcel = $f['parcel']->fresh();
        $this->assertSame($f['first']->id, (int) $parcel->courier_id);
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);
        $this->assertNull($parcel->handover_at);
        $this->assertSame(1, $parcel->deliveryAssignments()->where('role', 'pickup')->count());
        $this->assertFalse($f['first']->fresh()->is_available);
        Notification::assertSentToTimes($f['first']->rider, PickupAssigned::class, 1);
    }

    public function test_pickup_reservation_still_requires_f2_confirmation(): void
    {
        $f = $this->fixture();
        app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup');
        $this->actingAs($f['first']->rider, 'rider')->post(route('rider.pickups.confirm', $f['parcel']))
            ->assertRedirect()->assertSessionHasErrors('verification_token');
        $this->assertSame(OrderStatus::ReadyForPickup, $f['parcel']->fresh()->status);
    }

    public function test_stale_available_flag_does_not_allow_an_overloaded_rider(): void
    {
        $f = $this->fixture();
        $active = SellerOrder::factory()->create([
            'seller_id' => $f['seller']->id,
            'status' => OrderStatus::PickedUp->value,
            'courier_id' => $f['first']->id,
        ]);
        $this->weightParcel($active, 25000, $f['seller']);
        $result = app(CourierAssignmentService::class)->reservePickup($f['parcel'], $f['provider'], $f['first']->id);
        $this->assertNull($result);
        $this->assertNull($f['parcel']->fresh()->courier_id);
        $this->assertSame(1, $f['first']->fresh()->activeWorkload());
    }

    public function test_ties_use_rider_id_after_provider_station_and_coverage_filtering(): void
    {
        $f = $this->fixture();
        $other = LogisticsProvider::factory()->create();
        $this->assignableCourier($other, [], 'San Fernando', '030540100');
        $f['second']->forceFill(['max_weight_grams' => 100000])->save();
        $this->assertSame($f['first']->id, app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup')['courier']->id);
    }

    public function test_suspended_inactive_and_pending_riders_are_excluded(): void
    {
        foreach (['suspended', 'deactivated', 'pending'] as $status) {
            $f = $this->fixture();
            $f['first']->rider->forceFill(['status' => $status])->save();
            $result = app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup');
            $this->assertSame('reserved', $result['status']);
            $this->assertSame($f['second']->id, $result['courier']->id);
        }
    }

    public function test_wrong_station_is_excluded_even_when_an_area_covers_the_same_barangay(): void
    {
        $f = $this->fixture();
        $station = $f['first']->rider->station->replicate();
        $station->name = 'Wrong station';
        $station->save();
        $f['first']->rider->forceFill(['station_hub_id' => $station->id])->save();
        $this->assertSame($f['second']->id, app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup')['courier']->id);
    }

    public function test_a_riders_explicit_assigned_barangay_must_cover_the_parcel(): void
    {
        $f = $this->fixture();
        $area = $f['first']->area->replicate(['active_barangay_code']);
        $area->barangay_code = '0305401002';
        $area->name = 'Other barangay';
        $area->save();
        $f['first']->forceFill(['area_id' => $area->id])->save();
        $this->assertSame($f['second']->id, app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup')['courier']->id);
    }

    public function test_duplicate_dispatch_is_idempotent_and_a_second_parcel_cannot_overload_the_same_rider(): void
    {
        $f = $this->fixture();
        // The second Rider cannot take the next parcel either: 10,000 g > 5,000 g.
        $f['second']->forceFill(['max_weight_grams' => 5000])->save();
        $this->weightParcel($f['parcel'], 15000, $f['seller']);
        $service = app(CourierAssignmentService::class);
        $service->dispatch($f['parcel'], 'pickup');
        $service->dispatch($f['parcel'], 'pickup');
        $this->assertSame(1, $f['parcel']->deliveryAssignments()->count());
        $f['first']->forceFill(['is_available' => true])->save();
        $next = $f['parcel']->fresh()->replicate();
        $next->forceFill([
            'courier_id' => null,
            'order_id' => Order::factory()->create([
                'shipping_address' => ['barangay_code' => '0305401001', 'municipality' => 'San Fernando'],
            ])->id,
        ]);
        $next->save();
        $this->weightParcel($next, 10000, $f['seller']);
        // 15,000 g already carried + 10,000 g new = 25,000 g, over the 20,000 g limit.
        $this->assertSame('unavailable', $service->dispatch($next, 'pickup')['status']);
        $this->assertNull($next->fresh()->courier_id);
    }

    private function weightParcel(SellerOrder $parcel, int $grams, Seller $seller): void
    {
        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => Product::factory()->create([
                'seller_id' => $seller->id,
                'weight_grams' => $grams,
            ])->id,
            'quantity' => 1,
        ]);
    }

    public function test_unavailable_pickup_dispatch_is_visible_and_retry_recovers(): void
    {
        $f = $this->fixture();
        Notification::fake();
        $f['first']->rider->forceFill(['status' => 'pending'])->save();
        $f['second']->rider->forceFill(['status' => 'pending'])->save();
        $service = app(CourierAssignmentService::class);
        $service->dispatch($f['parcel'], 'pickup');
        $this->assertTrue($f['parcel']->fresh()->needs_reassignment);
        $this->actingAs($f['provider'], 'logistics')->get(route('logistics.incoming-parcels.index'))
            ->assertOk()->assertSee('Needs attention')
            ->assertSee('Retry automatic dispatch');
        $this->post(route('logistics.dispatch.retry', [$f['parcel'], 'pickup']))->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($f['parcel']->fresh()->needs_reassignment);
        $f['second']->rider->forceFill(['status' => 'approved'])->save();
        $this->post(route('logistics.dispatch.retry', [$f['parcel'], 'pickup']))
            ->assertRedirect()->assertSessionHas('success');
        $parcel = $f['parcel']->fresh();
        $this->assertFalse($parcel->needs_reassignment);
        $this->assertSame($f['second']->id, (int) $parcel->courier_id);
        $this->assertSame(1, $parcel->deliveryAssignments()->count());
        Notification::assertSentToTimes($f['second']->rider, PickupAssigned::class, 1);
    }

    public function test_delivery_reservation_requires_sorting_and_physical_destination_station_arrival(): void
    {
        $f = $this->fixture(OrderStatus::AtSortingCenter);
        Notification::fake();
        $service = app(CourierAssignmentService::class);
        $this->assertSame('not_eligible', $service->dispatch($f['parcel'], 'delivery')['status']);
        $f['parcel']->forceFill(['status' => OrderStatus::Sorted, 'custody_provider_id' => $f['provider']->id,
            'custody_hub_id' => $f['first']->rider->station->parent_hub_id])->save();
        $this->assertSame('unavailable', $service->dispatch($f['parcel']->fresh(), 'delivery')['status']);
        $this->assertNull($f['parcel']->fresh()->delivery_courier_id);
        $f['parcel']->forceFill(['custody_hub_id' => $f['first']->rider->station_hub_id])->save();
        $result = $service->dispatch($f['parcel']->fresh(), 'delivery');
        $this->assertSame('reserved', $result['status']);
        $this->assertSame(OrderStatus::Sorted, $f['parcel']->fresh()->status);
        Notification::assertNotSentTo($f['first']->rider, DeliveryAssigned::class);
        $this->actingAs($f['provider'], 'logistics')->post(route('logistics.parcel-sorting.assignDelivery', $f['parcel']))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(OrderStatus::AssignedToRider, $f['parcel']->fresh()->status);
        Notification::assertSentTo($f['first']->rider, DeliveryAssigned::class);
        $this->assertSame(1, $f['parcel']->deliveryAssignments()->count());
    }

    public function test_delivery_reservation_cannot_run_during_an_active_manifest(): void
    {
        $f = $this->fixture(OrderStatus::Sorted);
        $manifestService = app(ManifestService::class);
        $manifest = $manifestService->create($f['provider'], $f['first']->rider->station_hub_id, $f['first']->rider->station->parent_hub_id);
        $manifestService->addParcel($manifest, $f['parcel']);
        $this->assertSame('unavailable', app(CourierAssignmentService::class)->dispatch($f['parcel'], 'delivery')['status']);
        $this->assertNull($f['parcel']->fresh()->delivery_courier_id);
    }

    public function test_manual_reassignment_requires_reason_and_records_previous_rider_actor_and_time(): void
    {
        $f = $this->fixture();
        $service = app(CourierAssignmentService::class);
        $service->dispatch($f['parcel'], 'pickup');
        $url = route('logistics.incoming-parcels.assignPickupRider', $f['parcel']);
        $payload = ['reassign' => 1, 'courier_id' => $f['second']->id, 'expected_courier_id' => $f['first']->id];
        $this->actingAs($f['provider'], 'logistics')->post($url, $payload)->assertSessionHasErrors('reason');
        $payload['reason'] = 'Original Rider cannot collect today.';
        $this->post($url, $payload)->assertRedirect()->assertSessionHas('success');
        $assignment = $f['parcel']->deliveryAssignments()->latest('id')->firstOrFail();
        $audit = json_decode($assignment->notes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($f['first']->rider_id, $audit['previous_rider_id']);
        $this->assertSame($f['second']->rider_id, $audit['rider_id']);
        $this->assertSame($payload['reason'], $audit['reason']);
        $this->assertSame($f['provider']->id, (int) $assignment->assigned_by_id);
        $this->assertNotNull($assignment->created_at);
        $this->post($url, $payload)->assertRedirect();
        $this->assertSame(2, $f['parcel']->deliveryAssignments()->count());
    }

    public function test_foreign_provider_cannot_retry_or_reassign_dispatch(): void
    {
        $f = $this->fixture();
        $other = LogisticsProvider::factory()->create();
        $this->actingAs($other, 'logistics')->post(route('logistics.dispatch.retry', [$f['parcel'], 'pickup']))->assertForbidden();
        $this->post(route('logistics.incoming-parcels.assignPickupRider', $f['parcel']), ['reassign' => 1])->assertForbidden();
        $this->assertDatabaseMissing('delivery_assignments', ['seller_order_id' => $f['parcel']->id]);
    }

    public function test_verified_pickup_cannot_be_reassigned_to_a_different_collector(): void
    {
        $f = $this->fixture();
        app(CourierAssignmentService::class)->dispatch($f['parcel'], 'pickup');
        $f['parcel']->forceFill(['status' => OrderStatus::PickedUp, 'handover_at' => now()])->save();
        $this->assertNull(app(CourierAssignmentService::class)->reassign($f['parcel']->fresh(), $f['provider'], 'pickup', $f['second']->id, 'Rider unavailable'));
        $this->assertSame($f['first']->id, (int) $f['parcel']->fresh()->courier_id);
    }

    public function test_cross_provider_delivery_is_reserved_only_after_verified_destination_receipt(): void
    {
        $admin = Admin::factory()->create();
        $a = $this->operationalHandoffProvider('Laguna A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $b = $this->operationalHandoffProvider('Quezon B', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-AUTO-HANDOFF', OrderStatus::Sorted);
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'handoff_destination_provider_id' => $b['provider']->id])->save();
        $rider = Rider::factory()->create(['logistics_provider_id' => $b['provider']->id, 'station_hub_id' => $b['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $b['provider']->id, 'rider_id' => $rider->id, 'area_id' => $b['area']->id]);
        $this->activeHandoffLink($a['provider'], $b['provider'], $admin);
        $service = app(ParcelHandoffService::class);
        $handoff = $service->request($parcel, $a['provider']);
        $handoff = $service->accept($handoff, $b['provider']);
        $scan = $service->verifyScan($handoff, $a['provider'], $parcel->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $a['provider'], $scan['verification_token']);
        $this->assertNull($parcel->fresh()->delivery_courier_id);
        $this->assertNull($parcel->fresh()->custody_provider_id);
        $scan = $service->verifyScan($handoff, $b['provider'], $parcel->tracking_number, 'receive');
        $service->receive($handoff, $b['provider'], $scan['verification_token']);
        $this->assertSame($courier->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame($b['provider']->id, $parcel->fresh()->custody_provider_id);
        $this->assertSame($a['provider']->id, $parcel->fresh()->carrier_provider_id);
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
    }

    public function test_internal_station_receipt_retries_a_waiting_delivery_reservation(): void
    {
        $f = $this->fixture(OrderStatus::Sorted);
        $station = $f['first']->rider->station;
        $f['parcel']->forceFill(['custody_provider_id' => $f['provider']->id, 'custody_hub_id' => $station->parent_hub_id])->save();
        $service = app(ManifestService::class);
        $manifest = $service->create($f['provider'], $station->parent_hub_id, $station->id);
        $item = $service->addParcel($manifest, $f['parcel']->fresh());
        $scan = $service->verifyScan($manifest, $f['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['provider'], [$item->id => $scan['verification_token']]);
        $this->assertNull($f['parcel']->fresh()->delivery_courier_id);
        $scan = $service->verifyScan($manifest->fresh(), $f['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($manifest->fresh(), $f['provider'], $item, $scan['verification_token']);
        $this->assertSame($f['first']->id, (int) $f['parcel']->fresh()->delivery_courier_id);
        $this->assertSame(OrderStatus::Sorted, $f['parcel']->fresh()->status);
    }

    public function test_provider_pagination_never_includes_a_foreign_carriers_parcels(): void
    {
        $f = $this->fixture();
        SellerOrder::factory()->count(26)->create(['seller_id' => $f['seller']->id,
            'carrier_provider_id' => $f['provider']->id, 'status' => OrderStatus::ReadyForPickup->value]);
        $other = LogisticsProvider::factory()->create();
        SellerOrder::factory()->create(['seller_id' => $f['seller']->id,
            'carrier_provider_id' => $other->id, 'status' => OrderStatus::ReadyForPickup->value,
            'tracking_number' => 'TRK-FOREIGN-PHASE6']);
        $this->actingAs($f['provider'], 'logistics')->get(route('logistics.incoming-parcels.index', ['page' => 2]))
            ->assertOk()->assertDontSee('TRK-FOREIGN-PHASE6')
            ->assertViewHas('shipments', fn ($rows): bool => $rows->total() === 27 && $rows->perPage() === 25 && $rows->count() === 2);
    }

    public function test_retry_reports_an_unavailable_reserved_rider_without_creating_another_assignment(): void
    {
        $f = $this->fixture();
        $service = app(CourierAssignmentService::class);
        $service->dispatch($f['parcel'], 'pickup');
        app(RiderRegistrationService::class)->suspend($f['first']->rider, $f['provider'], 'Safety review required.');
        $originalReason = $f['parcel']->fresh()->reassignment_reason;
        $this->assertSame('unavailable', $service->dispatch($f['parcel']->fresh(), 'pickup')['status']);
        $this->assertTrue($f['parcel']->fresh()->needs_reassignment);
        $this->assertSame($originalReason, $f['parcel']->fresh()->reassignment_reason);
        $this->assertSame($f['first']->id, (int) $f['parcel']->fresh()->courier_id);
        $this->assertSame(1, $f['parcel']->deliveryAssignments()->count());
    }

    public function test_reassigned_delivery_reservations_notify_the_new_rider_only_after_dispatch_confirmation(): void
    {
        $f = $this->fixture(OrderStatus::Sorted);
        Notification::fake();
        $service = app(CourierAssignmentService::class);
        $service->dispatch($f['parcel'], 'delivery');
        $service->reassign($f['parcel']->fresh(), $f['provider'], 'delivery', $f['second']->id, 'Original Rider unavailable.');
        Notification::assertNotSentTo($f['second']->rider, DeliveryAssigned::class);
        $this->assertSame(OrderStatus::Sorted, $f['parcel']->fresh()->status);
        $service->assignDelivery($f['parcel']->fresh(), $f['provider']);
        Notification::assertSentTo($f['second']->rider, DeliveryAssigned::class);
        $this->assertSame(OrderStatus::AssignedToRider, $f['parcel']->fresh()->status);
        $this->assertSame(2, $f['parcel']->deliveryAssignments()->count());
    }

    public function test_outer_transaction_rollback_never_dispatches_or_leaves_an_assignment(): void
    {
        $f = $this->fixture(OrderStatus::Preparing);
        DB::beginTransaction();
        app(SellerOrderStatusService::class)->transition($f['parcel'], OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller, actorId: $f['seller']->id);
        DB::rollBack();
        $this->assertSame(OrderStatus::Preparing, $f['parcel']->fresh()->status);
        $this->assertSame(0, $f['parcel']->deliveryAssignments()->count());
    }
}
