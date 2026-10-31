<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Rider\AssignmentReassigned;
use App\Notifications\Rider\DeliveryAssigned;
use App\Notifications\Rider\PickupAssigned;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Rider\RiderRegistrationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class RiderReassignmentTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    public static function activeLegs(): array
    {
        $cases = [];
        foreach (['pickup' => [OrderStatus::ReadyForPickup, OrderStatus::PickedUp], 'delivery' => [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]] as $role => $statuses) {
            foreach ($statuses as $status) {
                foreach (['suspended', 'deactivated'] as $condition) {
                    $cases[$role.' '.$status->value.' '.$condition] = [$role, $status, $condition];
                }
            }
        }

        return $cases;
    }

    private function fixture(string $role, OrderStatus $status): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $old = $this->assignableCourier($provider, ['is_available' => false], 'Quezon City', '137404000');
        $replacement = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $pickup = $role === 'pickup' ? $old : $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $parcel = SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved', 'barangay_code' => '1374040001'])->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '1374040001', 'municipality' => 'Quezon City']])->id,
            'status' => $status->value,
            'courier_id' => $pickup->id,
            'delivery_courier_id' => $role === 'delivery' ? $old->id : null,
            'origin_station_hub_id' => $pickup->rider->station_hub_id,
            'destination_area_id' => $replacement->area_id,
            'delivery_attempts' => 1,
        ])->fresh();

        return [$provider, $old, $replacement, $parcel];
    }

    private function url(string $role, SellerOrder $parcel): string
    {
        return $role === 'pickup'
            ? route('logistics.incoming-parcels.assignPickupRider', $parcel)
            : route('logistics.delivery-assignments.reassign', $parcel);
    }

    #[DataProvider('activeLegs')]
    public function test_reassignment_preserves_status_routing_and_other_leg(string $role, OrderStatus $status, string $condition): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture($role, $status);
        app(RiderRegistrationService::class)->{$condition === 'suspended' ? 'suspend' : 'deactivate'}($old->rider, $provider, 'Safety review required.');
        $before = $parcel->fresh();
        $this->actingAs($provider, 'logistics')->post($this->url($role, $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])
            ->assertRedirect()->assertSessionHas('success');
        $parcel->refresh();
        $column = $role === 'pickup' ? 'courier_id' : 'delivery_courier_id';
        $otherColumn = $role === 'pickup' ? 'delivery_courier_id' : 'courier_id';
        $this->assertSame($replacement->id, (int) $parcel->{$column});
        $this->assertSame($before->{$otherColumn}, $parcel->{$otherColumn});
        $this->assertSame($status, $parcel->status);
        $this->assertSame($before->origin_station_hub_id, $parcel->origin_station_hub_id);
        $this->assertSame($before->destination_area_id, $parcel->destination_area_id);
        $this->assertSame(1, $parcel->delivery_attempts);
        $this->assertFalse($parcel->needs_reassignment);
        $this->assertFalse($replacement->fresh()->is_available);
        $this->assertFalse($old->fresh()->is_available);
        $this->assertDatabaseHas('delivery_assignments', ['seller_order_id' => $parcel->id, 'courier_id' => $replacement->id, 'role' => $role]);
        $this->assertSame(0, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
        Notification::assertSentTo($old->rider, AssignmentReassigned::class);
        if ($role === 'pickup') {
            // A pickup reassignment stays silent until Logistics confirms the dispatch.
            Notification::assertNotSentTo($replacement->rider, PickupAssigned::class);
            $this->actingAs($provider, 'logistics')
                ->post(route('logistics.incoming-parcels.confirmPickupDispatch', $parcel))
                ->assertRedirect()->assertSessionHas('success');
            Notification::assertSentToTimes($replacement->rider, PickupAssigned::class, 1);
        } else {
            Notification::assertSentTo($replacement->rider, DeliveryAssigned::class);
        }
        $this->actingAs($provider, 'logistics')->get(route($role === 'pickup' ? 'logistics.incoming-parcels.index' : 'logistics.delivery-assignments.index'))
            ->assertOk()->assertSee('Reassign Rider');
    }

    #[DataProvider('activeLegs')]
    public function test_ineligible_replacement_preserves_existing_assignment(string $role, OrderStatus $status, string $condition): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture($role, $status);
        $replacement->rider->forceFill(['status' => $condition])->save();
        $this->actingAs($provider, 'logistics')->post($this->url($role, $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])
            ->assertSessionHas('error');
        $this->assertSame($old->id, (int) $parcel->fresh()->{$role === 'pickup' ? 'courier_id' : 'delivery_courier_id'});
        $this->assertDatabaseMissing('delivery_assignments', ['seller_order_id' => $parcel->id]);
        Notification::assertNothingSent();
    }

    public function test_a_busy_but_active_replacement_is_allowed_when_weight_fits(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture('pickup', OrderStatus::PickedUp);
        $replacement->forceFill(['is_available' => false])->save();

        $this->actingAs($provider, 'logistics')
            ->post($this->url('pickup', $parcel), [
                'courier_id' => $replacement->id,
                'reassign' => 1,
                'reason' => 'Load balancing within this station.',
                'expected_courier_id' => $old->id,
            ])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame($replacement->id, (int) $parcel->fresh()->courier_id);
        $this->assertFalse($replacement->fresh()->is_available);
    }

    public function test_foreign_provider_and_foreign_replacement_are_rejected(): void
    {
        Notification::fake();
        foreach (['pickup' => OrderStatus::PickedUp, 'delivery' => OrderStatus::OutForDelivery] as $role => $status) {
            [$provider, $old, $replacement, $parcel] = $this->fixture($role, $status);
            $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
            $foreign = $this->assignableCourier($other, [], 'Quezon City', '137404000');
            $this->actingAs($other, 'logistics')->post($this->url($role, $parcel), ['courier_id' => $foreign->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])->assertForbidden();
            $this->actingAs($provider, 'logistics')->post($this->url($role, $parcel), ['courier_id' => $foreign->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])->assertSessionHas('error');
            $this->assertSame($old->id, (int) $parcel->fresh()->{$role === 'pickup' ? 'courier_id' : 'delivery_courier_id'});
        }
    }

    public function test_failed_and_final_deliveries_cannot_bypass_existing_lifecycle(): void
    {
        foreach ([OrderStatus::DeliveryFailed, OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Returned] as $status) {
            [$provider, $old, $replacement, $parcel] = $this->fixture('delivery', $status);
            $this->actingAs($provider, 'logistics')->post($this->url('delivery', $parcel), ['courier_id' => $replacement->id])->assertSessionHas('error');
            $this->assertSame($status, $parcel->fresh()->status);
            $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        }
    }

    public function test_suspending_historical_pickup_rider_does_not_flag_active_delivery(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture('delivery', OrderStatus::OutForDelivery);
        app(RiderRegistrationService::class)->suspend($parcel->courier->rider, $provider, 'Safety review required.');
        $this->assertFalse($parcel->fresh()->needs_reassignment);
        app(RiderRegistrationService::class)->suspend($old->rider, $provider, 'Safety review required.');
        $this->assertTrue($parcel->fresh()->needs_reassignment);
    }

    public function test_stale_reassignment_cannot_overwrite_new_assignment(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture('delivery', OrderStatus::OutForDelivery);
        $service = app(CourierAssignmentService::class);
        $this->assertNotNull($service->reassign($parcel, $provider, 'delivery', $replacement->id, 'Rider unavailable'));
        $this->assertNull($service->reassign($parcel, $provider, 'delivery', $old->id, 'Rider unavailable'));
        $this->assertSame($replacement->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(1, $parcel->deliveryAssignments()->count());
    }

    public function test_reassignment_rejects_replacements_from_another_station(): void
    {
        Notification::fake();
        foreach (['pickup' => OrderStatus::PickedUp, 'delivery' => OrderStatus::OutForDelivery] as $role => $status) {
            [$provider, $old, $replacement, $parcel] = $this->fixture($role, $status);
            $otherStation = $old->rider->station->replicate();
            $otherStation->name = 'Other Station';
            $otherStation->save();
            $replacement->rider->forceFill(['station_hub_id' => $otherStation->id])->save();
            $this->actingAs($provider, 'logistics')->post($this->url($role, $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])->assertSessionHas('error');
            $this->assertSame($old->id, (int) $parcel->fresh()->{$role === 'pickup' ? 'courier_id' : 'delivery_courier_id'});
        }
        Notification::assertNothingSent();
    }

    public function test_unknown_legacy_origin_is_not_inferred_during_reassignment(): void
    {
        [$provider, $old, $replacement, $parcel] = $this->fixture('pickup', OrderStatus::PickedUp);
        $parcel->forceFill(['origin_station_hub_id' => null])->save();
        $this->actingAs($provider, 'logistics')->post($this->url('pickup', $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])->assertSessionHas('error');
        $this->assertNull($parcel->fresh()->origin_station_hub_id);
        $this->assertSame($old->id, (int) $parcel->fresh()->courier_id);
    }

    public function test_previous_courier_with_another_active_task_remains_busy(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture('pickup', OrderStatus::ReadyForPickup);
        SellerOrder::factory()->create(['status' => OrderStatus::PickedUp->value, 'courier_id' => $old->id]);
        $this->actingAs($provider, 'logistics')->post($this->url('pickup', $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])->assertSessionHas('success');
        $this->assertFalse($old->fresh()->is_available);
    }

    public function test_guests_cannot_reassign_either_leg(): void
    {
        foreach (['pickup' => OrderStatus::PickedUp, 'delivery' => OrderStatus::OutForDelivery] as $role => $status) {
            [$provider, $old, $replacement, $parcel] = $this->fixture($role, $status);
            $this->post($this->url($role, $parcel), ['courier_id' => $replacement->id, 'reassign' => 1, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id])
                ->assertRedirect(route('home', ['login' => 1]));
            $this->assertSame($old->id, (int) $parcel->fresh()->{$role === 'pickup' ? 'courier_id' : 'delivery_courier_id'});
        }
    }

    public function test_failed_delivery_can_be_rescheduled_from_each_unavailable_rider_condition(): void
    {
        Notification::fake();
        foreach (['suspended', 'deactivated', 'unavailable'] as $condition) {
            [$provider, $old, $replacement, $parcel] = $this->fixture('delivery', OrderStatus::DeliveryFailed);
            if ($condition !== 'unavailable') {
                app(RiderRegistrationService::class)->{$condition === 'suspended' ? 'suspend' : 'deactivate'}($old->rider, $provider, 'Safety review required.');
            }
            $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id, 'reason' => 'Replacement Rider selected for the final attempt.'])
                ->assertSessionHas('success');
            $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
            $this->assertSame($replacement->id, (int) $parcel->fresh()->delivery_courier_id);
            $this->assertSame(1, $parcel->fresh()->delivery_attempts);
        }
    }

    public function test_stale_unassigned_pickup_cannot_overwrite_an_existing_assignment(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture('pickup', OrderStatus::ReadyForPickup);
        $stale = $parcel->fresh();
        $stale->forceFill(['courier_id' => null]);
        $this->assertNull(app(CourierAssignmentService::class)->reservePickup($stale, $provider, $replacement->id, true));
        $this->assertSame($old->id, (int) $parcel->fresh()->courier_id);
        Notification::assertNothingSent();
    }
}
