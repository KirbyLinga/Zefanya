<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;

class RiderDashboardTest extends RiderTestCase
{
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
