<?php

namespace Tests\Feature\Rider;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderStatus;
use App\Http\Middleware\Rider\EnsureRiderActive;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/** Audit H-1: a suspended Logistics company's riders stop working, apart from an Admin-granted wind-down. */
class SuspendedProviderRiderTest extends RiderTestCase
{
    private function suspendProvider(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.users.logistics.suspend', $this->provider), ['reason' => 'Unremitted COD under review.'])
            ->assertSessionHas('success');
    }

    private function grantWindDown(int $hours = 24): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.users.logistics.rider-wind-down', $this->provider), [
                'hours' => $hours,
                'reason' => 'Let riders finish parcels already with buyers.',
            ])
            ->assertSessionHas('success');
    }

    private function outForDelivery(): SellerOrder
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 150000]);

        return $parcel;
    }

    private function deliver(SellerOrder $parcel): TestResponse
    {
        return $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'received_by' => 'Maria Buyer',
            'cod_amount' => '1500.00',
        ]));
    }

    public function test_suspending_the_provider_signs_its_riders_out_of_the_panel(): void
    {
        $this->suspendProvider();

        $this->assertFalse($this->rider->fresh()->isOperational());
        $this->actingAsRider()->get(route('rider.dashboard'))->assertRedirect(route('home', ['login' => 1]));
        $this->assertGuest('rider');
    }

    public function test_a_suspended_providers_rider_cannot_sign_in(): void
    {
        $this->suspendProvider();

        $this->post(route('unified.login'), ['email' => $this->rider->email, 'password' => 'Rider@2026']);

        $this->assertGuest('rider');
    }

    public function test_a_suspended_providers_rider_token_is_refused_by_the_api(): void
    {
        $token = $this->rider->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;
        $this->suspendProvider();

        $this->withToken($token)->getJson(route('api.rider.profile'))->assertForbidden();
    }

    public function test_a_suspended_providers_riders_are_not_assignable(): void
    {
        $this->assertTrue(Courier::query()->eligibleForAssignment()->whereKey($this->courier->id)->exists());

        $this->suspendProvider();

        $this->assertFalse(Courier::query()->eligibleForAssignment()->whereKey($this->courier->id)->exists());
        $this->assertFalse($this->courier->fresh()->isEligibleForAssignment());
    }

    public function test_without_a_wind_down_an_out_for_delivery_parcel_stays_with_the_rider_unchanged(): void
    {
        $parcel = $this->outForDelivery();
        $this->suspendProvider();

        $this->deliver($parcel)->assertRedirect(route('home', ['login' => 1]));

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, CodRemittance::query()->count());
    }

    public function test_a_wind_down_lets_riders_finish_a_parcel_already_out_for_delivery(): void
    {
        $parcel = $this->outForDelivery();
        $this->suspendProvider();
        $this->grantWindDown();

        $this->actingAsRider()->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertSee(EnsureRiderActive::WIND_DOWN_MESSAGE)
            ->assertSee($parcel->tracking_number);

        $this->deliver($parcel)->assertRedirect(route('rider.deliveries.index'))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame(150000, CodRemittance::query()->where('seller_order_id', $parcel->id)->sole()->amount_minor);
    }

    public function test_a_wind_down_never_allows_new_pickups_or_starting_a_delivery(): void
    {
        $assigned = $this->parcel(
            OrderStatus::AssignedToRider,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );
        $this->suspendProvider();
        $this->grantWindDown();

        $this->actingAsRider()->get(route('rider.pickups.index'))
            ->assertRedirect(route('rider.deliveries.index'))
            ->assertSessionHas('error', EnsureRiderActive::WIND_DOWN_MESSAGE);
        $this->actingAsRider()->post(route('rider.deliveries.start', $assigned), [
            'verification_token' => $this->handoffToken($assigned, ParcelHandoffVerification::ACTION_START_DELIVERY),
        ])->assertRedirect(route('rider.deliveries.index'));

        $this->assertSame(OrderStatus::AssignedToRider, $assigned->fresh()->status);
        // Not listed either: only parcels already out for delivery can be finished.
        $this->actingAsRider()->get(route('rider.deliveries.index'))->assertOk()->assertDontSee($assigned->tracking_number);
        $this->assertFalse(Courier::query()->eligibleForAssignment()->whereKey($this->courier->id)->exists());
    }

    public function test_an_expired_wind_down_blocks_the_rider_again(): void
    {
        $parcel = $this->outForDelivery();
        $this->suspendProvider();
        $this->grantWindDown(12);

        $this->travel(13)->hours();

        $this->deliver($parcel)->assertRedirect(route('home', ['login' => 1]));
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
    }

    public function test_admin_can_end_a_wind_down_early_and_it_is_audited(): void
    {
        $this->suspendProvider();
        $this->grantWindDown();
        $this->grantWindDown(0);

        $this->assertNull($this->provider->fresh()->rider_wind_down_until);
        $this->assertFalse($this->rider->fresh()->isInWindDown());
        $this->assertDatabaseHas('account_action_histories', ['account_id' => $this->provider->id, 'account_type' => 'logistics', 'action' => 'rider_wind_down']);
        $this->assertDatabaseHas('account_action_histories', ['account_id' => $this->provider->id, 'account_type' => 'logistics', 'action' => 'wind_down_ended']);
    }

    public function test_a_wind_down_is_only_available_for_a_suspended_provider_and_is_bounded(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.logistics.rider-wind-down', $this->provider), ['hours' => 24, 'reason' => 'Not suspended yet.'])
            ->assertSessionHas('error');
        $this->assertNull($this->provider->fresh()->rider_wind_down_until);

        $this->suspendProvider();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.logistics.rider-wind-down', $this->provider), ['hours' => 73, 'reason' => 'Too long.'])
            ->assertSessionHasErrors('hours');
        $this->assertNull($this->provider->fresh()->rider_wind_down_until);
    }

    public function test_reactivation_clears_the_wind_down_and_restores_normal_work(): void
    {
        $this->suspendProvider();
        $this->grantWindDown();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.users.logistics.reactivate', $this->provider), ['reason' => 'Remittance settled.'])
            ->assertSessionHas('success');

        $provider = $this->provider->fresh();
        $this->assertSame(LogisticsProviderStatus::Approved, $provider->status);
        $this->assertNull($provider->rider_wind_down_until);
        $this->assertTrue($this->rider->fresh()->isOperational());
        $this->actingAsRider()->get(route('rider.pickups.index'))->assertOk();
    }

    public function test_the_admin_users_page_shows_parcels_still_with_a_suspended_providers_riders(): void
    {
        $this->outForDelivery();
        $this->suspendProvider();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get(route('admin.users.index', ['type' => 'logistics']))
            ->assertOk()
            ->assertSee('1 parcel out for delivery', false)
            ->assertSee(route('admin.users.logistics.rider-wind-down', $this->provider), false);
    }
}
