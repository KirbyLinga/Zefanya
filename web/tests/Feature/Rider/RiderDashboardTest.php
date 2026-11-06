<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\RiderAssignmentsNeedReassignment;
use App\Services\Rider\RiderAvailabilityService;
use Illuminate\Support\Facades\Notification;

class RiderDashboardTest extends RiderTestCase
{
    public function test_going_offline_flags_only_jobs_not_started_and_going_online_clears_them(): void
    {
        Notification::fake();
        $c = ['courier_id' => $this->courier->id];
        $d = ['delivery_courier_id' => $this->courier->id];
        $reservedPickup = $this->parcel(OrderStatus::ReadyForPickup, $c, ['handover_at' => null]);
        $reservedDelivery = $this->parcel(OrderStatus::Sorted, $d);
        $assignedDelivery = $this->parcel(OrderStatus::AssignedToRider, $d);
        $collected = $this->parcel(OrderStatus::PickedUp, $c, ['handover_at' => now()]);
        $outForDelivery = $this->parcel(OrderStatus::OutForDelivery, $d);
        $otherFlag = $this->parcel(OrderStatus::AssignedToRider, $d, [
            'needs_reassignment' => true, 'reassignment_reason' => 'Vehicle breakdown.', 'reassignment_flagged_at' => now(),
        ]);

        $this->actingAsRider()->post(route('rider.availability.update'), ['online' => 0])
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '3 job(s)'));

        foreach ([$reservedPickup, $reservedDelivery, $assignedDelivery] as $parcel) {
            $parcel->refresh();
            $this->assertTrue($parcel->needs_reassignment);
            $this->assertStringStartsWith(RiderAvailabilityService::FLAG_PREFIX, $parcel->reassignment_reason);
        }
        // Parcels in hand stay with the Rider; an existing flag keeps its reason.
        $this->assertFalse($collected->fresh()->needs_reassignment);
        $this->assertFalse($outForDelivery->fresh()->needs_reassignment);
        $this->assertSame('Vehicle breakdown.', $otherFlag->fresh()->reassignment_reason);
        Notification::assertSentTo($this->provider, RiderAssignmentsNeedReassignment::class,
            fn (RiderAssignmentsNeedReassignment $n): bool => $n->parcelCount === 3);

        // Logistics sees the Rider as offline.
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertSee('Offline');

        $this->actingAsRider()->post(route('rider.availability.update'), ['online' => 1]);
        foreach ([$reservedPickup, $reservedDelivery, $assignedDelivery] as $parcel) {
            $parcel->refresh();
            $this->assertFalse($parcel->needs_reassignment);
            $this->assertNull($parcel->reassignment_reason);
        }
        $this->assertSame('Vehicle breakdown.', $otherFlag->fresh()->reassignment_reason);
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertSee('Online');
    }

    public function test_rider_can_go_offline_and_back_online_while_keeping_assigned_jobs(): void
    {
        $held = $this->parcel(OrderStatus::AssignedToRider, ['delivery_courier_id' => $this->courier->id]);
        $this->actingAsRider()->get(route('rider.dashboard'))->assertOk()->assertSee('Go offline');

        $this->post(route('rider.availability.update'), ['online' => 0])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($this->rider->fresh()->is_online);
        $this->assertNotNull($this->rider->fresh()->online_changed_at);

        // Offline Riders still sign in and keep their existing jobs.
        $this->get(route('rider.dashboard'))->assertOk()->assertSee('You are offline')->assertSee('Go online');
        $this->get(route('rider.deliveries.index'))->assertOk()->assertSee((string) $held->tracking_number);

        $this->post(route('rider.availability.update'), ['online' => 1])->assertRedirect();
        $this->assertTrue($this->rider->fresh()->is_online);
    }

    public function test_dashboard_reports_real_counts_for_the_signed_in_rider_only(): void
    {
        $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $this->parcel(OrderStatus::PickedUp, ['courier_id' => $this->courier->id]);

        $this->parcel(OrderStatus::AssignedToRider, ['delivery_courier_id' => $this->courier->id]);
        $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);

        $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);
        $this->parcel(OrderStatus::Completed, ['delivery_courier_id' => $this->courier->id]);

        $flagged = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        $flagged->forceFill([
            'needs_reassignment' => true,
            'reassignment_reason' => 'Assigned rider is unavailable.',
        ])->save();

        // Another rider's work must never leak into this dashboard.
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);
        $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $otherCourier->id]);

        $response = $this->actingAsRider()->get(route('rider.dashboard'))->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['pending_pickups']);
        $this->assertSame(3, $stats['active_deliveries']);
        $this->assertSame(2, $stats['completed_deliveries']);
        $this->assertSame(1, $stats['needs_attention']);
        $this->assertSame(1, $stats['attention_parcels']->count());

        $response->assertSee($this->provider->business_name);
        $response->assertSee($this->stationForProvider($this->provider)->name);
    }

    public function test_dashboard_renders_zeroes_without_demo_data(): void
    {
        $response = $this->actingAsRider()->get(route('rider.dashboard'))->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(0, $stats['pending_pickups']);
        $this->assertSame(0, $stats['active_deliveries']);
        $this->assertSame(0, $stats['completed_deliveries']);
        $this->assertSame(0, $stats['needs_attention']);
        $response->assertSee('Nothing needs your attention right now.');
    }

    public function test_dashboard_hides_another_companys_parcels(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $otherCourier = $this->assignableCourier($otherProvider, [], 'Quezon City', '137404000');

        $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $otherCourier->id]);

        $stats = $this->actingAsRider()->get(route('rider.dashboard'))->viewData('stats');

        $this->assertSame(0, $stats['pending_pickups']);
    }

    public function test_rider_navigation_and_account_page_are_available(): void
    {
        $this->actingAsRider()->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Pickup Assignments')
            ->assertSee('Delivery Assignments')
            ->assertSee('Delivery History')
            ->assertSee('My Account');

        $this->get(route('rider.account.show'))
            ->assertOk()
            ->assertSee($this->rider->email)
            ->assertSee($this->provider->business_name);
    }
}
