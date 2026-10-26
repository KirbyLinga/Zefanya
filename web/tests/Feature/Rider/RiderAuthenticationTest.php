<?php

namespace Tests\Feature\Rider;

use App\Models\Courier;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;

class RiderAuthenticationTest extends RiderTestCase
{
    public function test_approved_operational_rider_can_log_in_and_reach_the_dashboard(): void
    {
        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticated('rider');
        $this->assertSame($this->rider->id, auth('rider')->id());

        $this->get(route('rider.dashboard'))->assertOk()->assertSee('Rider Dashboard');
    }

    public function test_pending_rejected_suspended_and_deactivated_riders_cannot_log_in(): void
    {
        foreach ([
            Rider::STATUS_PENDING,
            Rider::STATUS_PENDING_ACTIVATION,
            Rider::STATUS_REJECTED,
            Rider::STATUS_SUSPENDED,
            Rider::STATUS_DEACTIVATED,
        ] as $status) {
            $rider = $this->rider->fresh();
            $rider->forceFill(['status' => $status])->save();

            $this->post(route('unified.login'), [
                'email' => $rider->email,
                'password' => 'Rider@2026',
            ])->assertSessionHasErrors('email');

            $this->assertGuest('rider');
        }
    }

    public function test_unverified_email_blocks_login_and_operational_access(): void
    {
        $this->rider->forceFill(['email_verified_at' => null])->save();

        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('rider');
    }

    public function test_rider_without_a_station_or_courier_cannot_log_in(): void
    {
        $this->rider->forceFill(['station_hub_id' => null])->save();

        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('rider');

        $this->rider->forceFill(['station_hub_id' => $this->stationForProvider($this->provider)->id])->save();
        Courier::query()->where('id', $this->courier->id)->delete();

        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('rider');
    }

    public function test_station_deactivation_blocks_login(): void
    {
        $this->stationForProvider($this->provider)->forceFill(['is_active' => false])->save();

        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('rider');
    }

    public function test_an_existing_session_is_revoked_when_the_rider_is_suspended(): void
    {
        $this->actingAsRider()->get(route('rider.dashboard'))->assertOk();

        $this->rider->forceFill(['status' => Rider::STATUS_SUSPENDED])->save();

        $this->get(route('rider.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]));

        $this->assertGuest('rider');
    }

    public function test_an_existing_session_is_revoked_when_the_station_is_deactivated(): void
    {
        $this->actingAsRider()->get(route('rider.dashboard'))->assertOk();

        $this->stationForProvider($this->provider)->forceFill([
            'approval_status' => LogisticsHub::APPROVAL_REJECTED,
        ])->save();

        // The guard keeps one in-memory user for the whole test; a real request
        // re-reads the rider (and its station) from the session/database.
        $this->rider->refresh();

        $this->get(route('rider.dashboard'))->assertRedirect(route('home', ['login' => 1]));
        $this->assertGuest('rider');
    }

    public function test_two_active_guards_are_collapsed_to_the_panel_being_visited(): void
    {
        // Simulate a stale shared browser session holding both accounts.
        $this->actingAs($this->buyer, 'buyer');
        $this->actingAsRider();

        $this->get(route('rider.dashboard'))->assertOk();

        $this->assertAuthenticated('rider');
        $this->assertGuest('buyer');
    }

    public function test_an_authenticated_rider_cannot_open_the_login_surface_again(): void
    {
        $this->actingAsRider();

        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertRedirect(route('rider.dashboard'));
    }

    public function test_logout_invalidates_every_guard(): void
    {
        $this->actingAsRider();

        $this->post(route('unified.logout'))->assertRedirect(route('home'));

        $this->assertGuest('rider');
        $this->assertGuest('buyer');
        $this->assertGuest('logistics');
        $this->assertGuest('seller');
    }

    public function test_cross_company_rider_cannot_be_loaded_through_another_providers_station(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->rider->forceFill(['logistics_provider_id' => $otherProvider->id])->save();
        $this->courier->forceFill(['logistics_provider_id' => $otherProvider->id])->save();

        // The rider's Courier/company now disagree with the station's owner.
        $this->post(route('unified.login'), [
            'email' => $this->rider->email,
            'password' => 'Rider@2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('rider');
    }
}
