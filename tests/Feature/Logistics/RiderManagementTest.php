<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\RiderAssignmentsNeedReassignment;
use App\Notifications\Rider\AccountStatusChanged;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Rider\RiderRegistrationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class RiderManagementTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function provider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function pendingRider(LogisticsProvider $provider): Rider
    {
        return Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'status' => Rider::STATUS_PENDING,
            'email_verified_at' => now(),
        ]);
    }

    private function secondStation(LogisticsProvider $provider): LogisticsHub
    {
        $first = $this->stationForProvider($provider);

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $first->parent_hub_id,
            'name' => 'Second Station Hub '.$provider->id,
            'hub_type' => LogisticsHub::STATION,
            'province_name' => 'Pampanga',
            'municipality' => 'Angeles',
            'city_municipality_code' => '030540200',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }

    public function test_approval_requires_an_operational_station_and_links_exactly_one_courier(): void
    {
        $provider = $this->provider();
        $rider = $this->pendingRider($provider);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider))
            ->assertSessionHasErrors('station_hub_id');

        $this->assertSame(Rider::STATUS_PENDING, $rider->fresh()->status);
        $this->assertDatabaseCount('couriers', 0);

        $station = $this->stationForProvider($provider);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider), ['station_hub_id' => $station->id])
            ->assertRedirect(route('logistics.riders.show', $rider));

        $rider->refresh();
        $this->assertSame(Rider::STATUS_APPROVED, $rider->status);
        $this->assertSame($station->id, $rider->station_hub_id);
        $this->assertSame($provider->id, (int) $rider->decided_by);
        $this->assertDatabaseCount('couriers', 1);
        $this->assertDatabaseHas('couriers', ['rider_id' => $rider->id, 'logistics_provider_id' => $provider->id]);
        $this->assertTrue($rider->fresh()->isEligibleForAssignment());
    }

    public function test_approval_rejects_inactive_foreign_and_non_station_hubs(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $rider = $this->pendingRider($provider);

        $inactive = $this->stationForProvider($provider);
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider), ['station_hub_id' => $inactive->id])
            ->assertSessionHasErrors('station_hub_id');

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider), ['station_hub_id' => $this->stationForProvider($other)->id])
            ->assertSessionHasErrors('station_hub_id');

        $province = LogisticsHub::query()->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)->firstOrFail();
        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider), ['station_hub_id' => $province->id])
            ->assertSessionHasErrors('station_hub_id');

        $this->assertSame(Rider::STATUS_PENDING, $rider->fresh()->status);
        $this->assertDatabaseCount('couriers', 0);
    }

    public function test_suspending_a_rider_flags_open_parcels_without_rewriting_statuses_and_notifies_logistics(): void
    {
        Notification::fake();
        $provider = $this->provider();
        $courier = $this->assignableCourier($provider, ['is_available' => true]);
        $rider = $courier->rider;

        $pickup = SellerOrder::factory()->create(['status' => OrderStatus::ReadyForPickup->value]);
        $pickup->forceFill(['courier_id' => $courier->id])->save();

        $delivery = SellerOrder::factory()->create(['status' => OrderStatus::OutForDelivery->value]);
        $delivery->forceFill(['delivery_courier_id' => $courier->id])->save();

        $delivered = SellerOrder::factory()->create(['status' => OrderStatus::Delivered->value]);
        $delivered->forceFill(['delivery_courier_id' => $courier->id])->save();

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.suspend', $rider), ['reason' => 'Medical leave'])
            ->assertRedirect(route('logistics.riders.show', $rider));

        $this->assertSame(Rider::STATUS_SUSPENDED, $rider->fresh()->status);
        $this->assertSame('Medical leave', $rider->fresh()->status_reason);
        $this->assertFalse($courier->fresh()->is_available);
        $this->assertTrue($pickup->fresh()->needs_reassignment);
        $this->assertTrue($delivery->fresh()->needs_reassignment);
        $this->assertFalse($delivered->fresh()->needs_reassignment);
        $this->assertSame(OrderStatus::ReadyForPickup, $pickup->fresh()->status);
        $this->assertSame(OrderStatus::OutForDelivery, $delivery->fresh()->status);
        Notification::assertSentTo($provider, RiderAssignmentsNeedReassignment::class, function ($notification) use ($rider): bool {
            return $notification->rider->is($rider) && $notification->parcelCount === 2;
        });
    }

    public function test_deactivated_rider_cannot_be_assigned_and_reactivation_requires_an_operational_station(): void
    {
        $provider = $this->provider();
        $courier = $this->assignableCourier($provider);
        $rider = $courier->rider;
        $station = $rider->station;
        $assignment = new CourierAssignmentService;

        $assigned = $assignment->assignPickup($this->readyOrder(['municipality' => 'Quezon City']), $provider);
        $this->assertNotNull($assigned);
        $this->assertSame($courier->id, $assigned->id);

        $service = app(RiderRegistrationService::class);
        $service->deactivate($rider, $provider, 'Resigned');

        $this->assertSame(Rider::STATUS_DEACTIVATED, $rider->fresh()->status);
        $this->assertFalse($rider->fresh()->isEligibleForAssignment());
        $this->assertFalse($courier->fresh()->isEligibleForAssignment());

        $station->forceFill(['is_active' => false])->save();

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.reactivate', $rider))
            ->assertForbidden();

        $this->assertSame(Rider::STATUS_DEACTIVATED, $rider->fresh()->status);

        $station->forceFill(['is_active' => true])->save();

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.riders.reactivate', $rider))
            ->assertRedirect(route('logistics.riders.show', $rider));

        $this->assertSame(Rider::STATUS_ACTIVE, $rider->fresh()->status);
        $this->assertTrue($rider->fresh()->isEligibleForAssignment());
    }

    public function test_only_the_owning_company_may_suspend_deactivate_or_move_a_rider(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $rider = $this->assignableCourier($provider)->rider;

        $this->actingAs($other, 'logistics')
            ->post(route('logistics.riders.suspend', $rider))
            ->assertForbidden();
        $this->actingAs($other, 'logistics')
            ->post(route('logistics.riders.deactivate', $rider))
            ->assertForbidden();
        $this->actingAs($other, 'logistics')
            ->patch(route('logistics.riders.station', $rider), ['station_hub_id' => $this->stationForProvider($other)->id])
            ->assertForbidden();

        $this->assertSame(Rider::STATUS_APPROVED, $rider->fresh()->status);
    }

    public function test_changing_station_rejects_foreign_or_inactive_hubs_and_clears_old_coverage(): void
    {
        $provider = $this->provider();
        $courier = $this->assignableCourier($provider);
        $rider = $courier->rider;
        $oldArea = $courier->area;
        $this->assertNotNull($oldArea);

        $this->actingAs($provider, 'logistics')
            ->patch(route('logistics.riders.station', $rider), ['station_hub_id' => $this->stationForProvider($this->provider())->id])
            ->assertSessionHasErrors('station_hub_id');

        $second = $this->secondStation($provider);

        $this->actingAs($provider, 'logistics')
            ->patch(route('logistics.riders.station', $rider), ['station_hub_id' => $second->id])
            ->assertRedirect(route('logistics.riders.show', $rider));

        $this->assertSame($second->id, $rider->fresh()->station_hub_id);
        $this->assertNull($courier->fresh()->area_id);

        $second->forceFill(['is_active' => false])->save();
        $this->actingAs($provider, 'logistics')
            ->patch(route('logistics.riders.station', $rider), ['station_hub_id' => $second->id])
            ->assertSessionHasErrors('station_hub_id');
    }

    public function test_coverage_linking_requires_an_eligible_rider_from_the_same_station(): void
    {
        $provider = $this->provider();
        $station = $this->stationForProvider($provider);
        $area = $this->coverMunicipality($provider, 'San Fernando', '030540100', $station);

        $bare = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.couriers.assign', $area), ['courier_id' => $bare->id])
            ->assertSessionHasErrors('courier_id');

        $courier = $this->assignableCourier($provider);
        $second = $this->secondStation($provider);
        $otherArea = $this->coverMunicipality($provider, 'Angeles', '030540200', $second);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.couriers.assign', $otherArea), ['courier_id' => $courier->id])
            ->assertSessionHasErrors('courier_id');

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.couriers.assign', $area), ['courier_id' => $courier->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($area->id, $courier->fresh()->area_id);
    }

    public function test_assignment_uses_station_coverage_and_excludes_suspended_or_unassigned_riders(): void
    {
        $provider = $this->provider();
        $courier = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $rider = $courier->rider;
        $service = app(RiderRegistrationService::class);

        $byCode = (new CourierAssignmentService)->assignPickup($this->readyOrder(['municipality' => 'Quezon City', 'municipality_code' => '137404000']), $provider);
        $this->assertNotNull($byCode);
        $this->assertSame($courier->id, $byCode->id);

        $service->suspend($rider, $provider, 'Suspended for safety review.');
        $this->assertNull((new CourierAssignmentService)->assignPickup($this->readyOrder(['municipality' => 'Quezon City', 'municipality_code' => '137404000']), $provider));

        $unassigned = Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
            'station_hub_id' => null,
        ]);
        Courier::factory()->create([
            'logistics_provider_id' => $provider->id,
            'rider_id' => $unassigned->id,
            'area_id' => null,
            'is_available' => true,
            'max_weight_grams' => 10000,
        ]);

        $this->assertNull((new CourierAssignmentService)->assignPickup($this->readyOrder(['municipality' => 'Quezon City', 'municipality_code' => '137404000']), $provider));
    }

    public function test_logistics_rider_screens_render_applications_station_actions_and_status_controls(): void
    {
        $provider = $this->provider();
        $station = $this->stationForProvider($provider);
        $application = $this->pendingRider($provider);
        $active = $this->assignableCourier($provider)->rider;

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.riders.index'))
            ->assertOk()
            ->assertSee('Pending applications')
            ->assertSee($application->email)
            ->assertSee($active->email)
            ->assertSee($station->name)
            ->assertSee('Approve');

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.riders.show', $application))
            ->assertOk()
            ->assertSee('Approve')
            ->assertSee('Rejection reason');

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.riders.show', $active))
            ->assertOk()
            ->assertSee('Suspend Rider')
            ->assertSee('Deactivate Rider')
            ->assertSee('Update Station');

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.riders.create'))
            ->assertOk()
            ->assertSee('Station Hub')
            ->assertSee('Residential Address');
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    private function readyOrder(array $shippingAddress = ['municipality' => 'San Fernando']): SellerOrder
    {
        $order = Order::factory()->create(['shipping_address' => $shippingAddress + ['barangay_code' => $this->fixtureBarangay($shippingAddress['municipality'])]]);
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['barangay_code' => $this->fixtureBarangay($shippingAddress['municipality'])])->id,
            'status' => OrderStatus::ReadyForPickup->value,
        ]);

        return $sellerOrder->fresh();
    }

    public function test_reject_and_suspend_require_a_reason_between_ten_and_three_hundred_characters(): void
    {
        Notification::fake();
        $provider = $this->provider();
        foreach (['reject', 'suspend'] as $action) {
            $rider = $action === 'reject' ? $this->pendingRider($provider) : $this->assignableCourier($provider)->rider;
            $originalStatus = $rider->status;
            foreach ([null, str_repeat('a', 9), str_repeat('a', 301)] as $reason) {
                $this->actingAs($provider, 'logistics')
                    ->post(route('logistics.riders.'.$action, $rider), ['reason' => $reason])
                    ->assertSessionHasErrors('reason');
                $this->assertSame($originalStatus, $rider->fresh()->status);
            }
        }
        Notification::assertNothingSent();
    }

    public function test_reject_and_suspend_notify_the_rider_with_the_exact_reason_at_both_length_limits(): void
    {
        Notification::fake();
        $provider = $this->provider();
        foreach (['reject', 'suspend'] as $action) {
            foreach ([10, 300] as $length) {
                $rider = $action === 'reject' ? $this->pendingRider($provider) : $this->assignableCourier($provider)->rider;
                $reason = str_repeat('x', $length - 3).'!?Z';
                $this->actingAs($provider, 'logistics')
                    ->post(route('logistics.riders.'.$action, $rider), ['reason' => $reason])
                    ->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame($reason, $rider->fresh()->status_reason);
                Notification::assertSentTo($rider, AccountStatusChanged::class, function ($notification, $channels) use ($rider, $reason): bool {
                    $payload = $notification->toArray($rider);
                    $mail = $notification->toMail($rider);

                    return $payload['reason'] === $reason
                        && str_ends_with($payload['message'], 'Reason: '.$reason)
                        && in_array('database', $channels, true)
                        && in_array('mail', $channels, true)
                        && in_array($payload['message'], $mail->introLines, true);
                });
            }
        }
    }

    public function test_service_rejects_missing_or_invalid_reasons_without_changing_rider_status(): void
    {
        Notification::fake();
        $provider = $this->provider();
        $service = app(RiderRegistrationService::class);
        foreach (['reject', 'suspend'] as $action) {
            $rider = $action === 'reject' ? $this->pendingRider($provider) : $this->assignableCourier($provider)->rider;
            $originalStatus = $rider->status;
            foreach ([null, str_repeat('a', 9), str_repeat('a', 301)] as $reason) {
                try {
                    if ($action === 'reject') {
                        $service->decide($rider, $provider, Rider::STATUS_REJECTED, $reason);
                    } else {
                        $service->suspend($rider, $provider, $reason);
                    }
                    $this->fail('Invalid reason should be rejected by the service.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('reason', $exception->errors());
                }
                $this->assertSame($originalStatus, $rider->fresh()->status);
            }
        }
        Notification::assertNothingSent();
    }
}
