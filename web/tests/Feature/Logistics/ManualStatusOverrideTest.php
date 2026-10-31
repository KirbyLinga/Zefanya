<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\LogisticsStatusOverrideService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class ManualStatusOverrideTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->courier = $this->assignableCourier($this->provider, ['is_available' => false], 'San Fernando', '030540100');
    }

    private function parcel(OrderStatus $status): SellerOrder
    {
        $parcel = SellerOrder::factory()->status($status)->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '0305401001'])->id,
            'courier_id' => $this->courier->id,
        ]);
        $parcel->forceFill(['origin_station_hub_id' => $this->courier->rider->station_hub_id, 'handover_at' => now()])->save();
        $parcel->order->update(['shipping_address' => ['municipality' => 'San Fernando', 'barangay_code' => '0305401001']]);

        return $parcel->fresh();
    }

    private function payload(OrderStatus $from, OrderStatus $to): array
    {
        return ['expected_status' => $from->value, 'status' => $to->value, 'reason' => 'Physical receipt verified against the hub register.'];
    }

    public function test_manual_receipt_records_exact_reason_actor_and_before_after_status_atomically(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);
        $payload = $this->payload(OrderStatus::PickedUp, OrderStatus::AtSortingCenter);
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.show', $parcel))->assertOk()->assertSee('Manual status override');
        $this->post(route('logistics.delivery-monitoring.override', $parcel), $payload)
            ->assertRedirect(route('logistics.delivery-monitoring.show', $parcel))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertTrue($this->courier->fresh()->is_available);
        $log = OperationsAuditLog::where('action', 'shipment_status_overridden')->sole();
        $this->assertSame($payload['reason'], $log->reason);
        $this->assertSame($this->provider->id, (int) $log->actor_id);
        $this->assertSame($this->provider->id, (int) $log->logistics_provider_id);
        $this->assertSame(['status' => OrderStatus::PickedUp->value], $log->old_values);
        $this->assertSame(['status' => OrderStatus::AtSortingCenter->value], $log->new_values);
        $history = StatusHistory::where('seller_order_id', $parcel->id)->sole();
        $this->assertStringContainsString($payload['reason'], $history->note);
    }

    public function test_manual_sorting_preserves_destination_routing(): void
    {
        $parcel = $this->parcel(OrderStatus::AtSortingCenter);
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::AtSortingCenter, OrderStatus::Sorted))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
        $this->assertNotNull($parcel->fresh()->destination_area_id);
        $this->assertSame(1, OperationsAuditLog::where('action', 'shipment_status_overridden')->count());
    }

    public function test_manual_assignment_uses_existing_rider_assignment_and_retry_limits(): void
    {
        $this->assignableCourier($this->provider, [], 'San Fernando', '030540100');
        $parcel = $this->parcel(OrderStatus::Sorted);
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::Sorted, OrderStatus::AssignedToRider))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertSame($this->provider->id, (int) $parcel->fresh()->deliveryCourier->logistics_provider_id);
        $this->assertNotNull($parcel->fresh()->deliveryAssignments()->first());
        $failed = $this->parcel(OrderStatus::DeliveryFailed);
        $failed->forceFill(['delivery_attempts' => 2])->save();
        $this->post(route('logistics.delivery-monitoring.override', $failed),
            $this->payload(OrderStatus::DeliveryFailed, OrderStatus::AssignedToRider))->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::DeliveryFailed, $failed->fresh()->status);
    }

    public function test_reason_is_required_and_between_ten_and_three_hundred_characters(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);
        foreach (['', str_repeat('x', 9), str_repeat('x', 301)] as $reason) {
            $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
                array_replace($this->payload(OrderStatus::PickedUp, OrderStatus::AtSortingCenter), ['reason' => $reason]))
                ->assertSessionHasErrors('reason');
        }
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertSame(0, OperationsAuditLog::count());
    }

    public function test_foreign_provider_stale_page_and_rider_only_delivery_are_rejected(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::PickedUp, OrderStatus::AtSortingCenter))->assertForbidden();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::AtSortingCenter, OrderStatus::Sorted))->assertSessionHasErrors('status');
        $out = $this->parcel(OrderStatus::OutForDelivery);
        $this->post(route('logistics.delivery-monitoring.override', $out),
            $this->payload(OrderStatus::OutForDelivery, OrderStatus::Delivered))->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::OutForDelivery, $out->fresh()->status);
        $this->assertSame(0, OperationsAuditLog::count());
    }

    public function test_audit_failure_rolls_back_status_history_and_courier_release(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);
        Event::listen('eloquent.creating: '.OperationsAuditLog::class, function (): void {
            throw new \RuntimeException('Audit storage unavailable');
        });
        try {
            app(LogisticsStatusOverrideService::class)->override($this->provider, $parcel,
                OrderStatus::PickedUp, OrderStatus::AtSortingCenter, 'Physical receipt verified against the register.');
            $this->fail('Audit failure must roll back the correction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.OperationsAuditLog::class);
        }
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertFalse($this->courier->fresh()->is_available);
        $this->assertSame(0, StatusHistory::where('seller_order_id', $parcel->id)->count());
    }

    public function test_open_incident_blocks_override_without_any_status_or_audit_change(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp);
        Incidents::create([
            'incident_seller_order_id' => $parcel->id,
            'reporter_id' => $this->provider->id, 'reporter_type' => 'logistics',
            'type' => 'damaged', 'description' => 'Packaging damaged during receipt.',
            'reason' => 'Visible damage reported.', 'status' => 'open',
        ]);
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::PickedUp, OrderStatus::AtSortingCenter))->assertSessionHasErrors();
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertSame(0, OperationsAuditLog::count());
    }

    public function test_reason_length_boundaries_are_accepted_and_duplicate_submissions_do_not_duplicate_audit(): void
    {
        foreach ([10, 300] as $length) {
            $parcel = $this->parcel(OrderStatus::PickedUp);
            $payload = array_replace($this->payload(OrderStatus::PickedUp, OrderStatus::AtSortingCenter), ['reason' => str_repeat('x', $length)]);
            $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel), $payload)
                ->assertSessionHasNoErrors();
            $this->post(route('logistics.delivery-monitoring.override', $parcel), $payload)->assertSessionHasErrors('status');
            $this->assertSame(1, OperationsAuditLog::where('action', 'shipment_status_overridden')->where('entity_id', $parcel->id)->count());
        }
    }

    public function test_no_available_rider_does_not_record_a_successful_override(): void
    {
        $parcel = $this->parcel(OrderStatus::Sorted);
        $this->courier->rider->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel),
            $this->payload(OrderStatus::Sorted, OrderStatus::AssignedToRider))->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
        $this->assertSame(0, OperationsAuditLog::count());
    }

    public function test_manual_retry_uses_the_existing_assignment_workflow_and_records_the_reason(): void
    {
        $parcel = $this->parcel(OrderStatus::DeliveryFailed);
        $parcel->forceFill(['delivery_attempts' => 1, 'delivery_courier_id' => $this->courier->id])->save();
        $this->assignableCourier($this->provider, [], 'San Fernando', '030540100');
        $payload = $this->payload(OrderStatus::DeliveryFailed, OrderStatus::AssignedToRider);
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.delivery-monitoring.override', $parcel), $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertSame(1, (int) $parcel->fresh()->delivery_attempts);
        $this->assertNotNull($parcel->fresh()->delivery_courier_id);
        $log = OperationsAuditLog::where('action', 'shipment_status_overridden')->sole();
        $this->assertSame($payload['reason'], $log->reason);
        $this->assertSame(['status' => OrderStatus::DeliveryFailed->value], $log->old_values);
    }
}
