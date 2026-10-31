<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class OperationsAuditLogTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'business_name' => 'Ninja Express',
        ]);
    }

    private function application(): Rider
    {
        return Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'status' => Rider::STATUS_PENDING,
            'email_verified_at' => now(),
        ]);
    }

    private function parcel(?int $courierId = null): SellerOrder
    {
        $parcel = SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
            'status' => OrderStatus::ReadyForPickup->value,
            'courier_id' => $courierId,
        ])->fresh();
        $parcel->order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        return $parcel;
    }

    private function log(string $action): OperationsAuditLog
    {
        return OperationsAuditLog::query()->where('action', $action)->sole();
    }

    public function test_approving_a_rider_records_who_what_and_the_old_and_new_values(): void
    {
        $rider = $this->application();
        $station = $this->stationForProvider($this->provider);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.approve', $rider), ['station_hub_id' => $station->id])
            ->assertSessionHas('success');

        $log = $this->log('rider_approved');
        $this->assertSame('Ninja Express', $log->actor_name);
        $this->assertSame((int) $this->provider->id, (int) $log->actor_id);
        $this->assertSame('rider', $log->entity_type);
        $this->assertSame((int) $rider->id, (int) $log->entity_id);
        $this->assertSame(Rider::STATUS_PENDING, $log->old_values['status']);
        $this->assertSame(Rider::STATUS_APPROVED, $log->new_values['status']);
        $this->assertSame($station->name, $log->new_values['station']);
        $this->assertNotNull($log->created_at);
    }

    public function test_rejecting_a_rider_records_the_reason(): void
    {
        $rider = $this->application();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.reject', $rider), ['reason' => 'License has expired.'])
            ->assertSessionHas('success');

        $this->assertSame('License has expired.', $this->log('rider_rejected')->reason);
    }

    public function test_suspending_and_reactivating_a_rider_are_both_recorded(): void
    {
        $rider = $this->assignableCourier($this->provider)->rider;
        $before = $rider->status;

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.suspend', $rider), ['reason' => 'Repeated late deliveries.'])
            ->assertSessionHas('success');

        $suspended = $this->log('rider_suspended');
        $this->assertSame($before, $suspended->old_values['status']);
        $this->assertSame(Rider::STATUS_SUSPENDED, $suspended->new_values['status']);
        $this->assertSame('Repeated late deliveries.', $suspended->reason);

        $this->post(route('logistics.riders.reactivate', $rider))->assertSessionHas('success');
        $this->assertSame(Rider::STATUS_SUSPENDED, $this->log('rider_reactivated')->old_values['status']);
    }

    public function test_a_refused_status_change_records_nothing(): void
    {
        $rider = $this->application();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.suspend', $rider), ['reason' => 'Not allowed yet.'])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, OperationsAuditLog::query()->count());
    }

    public function test_choosing_and_replacing_a_rider_by_hand_are_recorded_with_the_reason(): void
    {
        $first = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $second = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $first->id])
            ->assertSessionHas('success');

        $chosen = $this->log('rider_chosen_manually');
        $this->assertNull($chosen->old_values['pickup_rider']);
        $this->assertSame($first->rider->fullName(), $chosen->new_values['pickup_rider']);
        $this->assertSame('seller_order', $chosen->entity_type);

        $this->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), [
            'courier_id' => $second->id, 'reassign' => 1, 'reason' => 'Rider is unavailable.', 'expected_courier_id' => $first->id,
        ])->assertSessionHas('success');

        $replaced = $this->log('rider_reassigned');
        $this->assertSame($first->rider->fullName(), $replaced->old_values['pickup_rider']);
        $this->assertSame($second->rider->fullName(), $replaced->new_values['pickup_rider']);
        $this->assertSame('Rider is unavailable.', $replaced->reason);
    }

    public function test_automatic_dispatch_is_not_a_manual_override(): void
    {
        $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel();

        app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider);

        $this->assertNotNull($parcel->fresh()->courier_id);
        $this->assertSame(0, OperationsAuditLog::query()->count());
    }

    public function test_logs_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.reject', $this->application()), ['reason' => 'License has expired.']);
        $log = $this->log('rider_rejected');

        foreach ([
            fn () => $log->forceFill(['reason' => 'Changed'])->save(),
            fn () => $log->delete(),
            fn () => OperationsAuditLog::query()->whereKey($log->id)->update(['reason' => 'Changed']),
            fn () => OperationsAuditLog::query()->whereKey($log->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The audit log was changed.');
            } catch (LogicException) {
                // Expected.
            }
        }

        $this->assertSame('License has expired.', $log->fresh()->reason);
    }

    public function test_the_only_audit_log_routes_are_read_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit-logs'));

        $this->assertCount(2, $routes);
        $routes->each(fn ($route) => $this->assertSame(['GET', 'HEAD'], $route->methods()));
    }

    public function test_logistics_sees_only_its_own_activity(): void
    {
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $rider = $this->application();
        $otherRider = Rider::factory()->create(['logistics_provider_id' => $other->id, 'status' => Rider::STATUS_PENDING, 'email_verified_at' => now()]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.reject', $rider), ['reason' => 'Our reason.']);
        $this->actingAs($other, 'logistics')
            ->post(route('logistics.riders.reject', $otherRider), ['reason' => 'Their reason.']);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.audit-logs.index'))
            ->assertOk()
            ->assertSee('Rider rejected')
            ->assertSee('Our reason.')
            ->assertSee('Pending → Rejected', false)
            ->assertDontSee('Their reason.');
    }

    public function test_the_screen_filters_by_action(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.reject', $this->application()), ['reason' => 'Rejected one.']);

        $this->get(route('logistics.audit-logs.index', ['action' => 'rider_suspended']))
            ->assertOk()->assertDontSee('Rejected one.')->assertSee('No activity recorded yet.');
        $this->get(route('logistics.audit-logs.index', ['action' => 'not_real']))
            ->assertOk()->assertSee('Rejected one.');
    }

    public function test_admin_sees_every_company_and_moderators_cannot_open_it(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.riders.reject', $this->application()), ['reason' => 'Our reason.']);

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->get(route('admin.audit-logs.index'))
            ->assertOk()->assertSee('Ninja Express')->assertSee('Our reason.');

        $this->actingAs(Admin::factory()->create(['role' => 'moderator']), 'admin')
            ->get(route('admin.audit-logs.index'))
            ->assertForbidden();
    }
}
