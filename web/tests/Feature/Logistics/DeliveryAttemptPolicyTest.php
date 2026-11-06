<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Http\Controllers\Logistics\DeliveryAssignmentsController;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class DeliveryAttemptPolicyTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function fixture(OrderStatus $status = OrderStatus::OutForDelivery, int $failures = 0): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $old = $this->assignableCourier($provider, ['is_available' => $status === OrderStatus::DeliveryFailed], 'Quezon City', '137404000');
        $replacement = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $parcel = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '1374040001', 'municipality' => 'Quezon City']])->id,
            'status' => $status->value, 'courier_id' => $old->id, 'delivery_courier_id' => $old->id,
            'origin_station_hub_id' => $old->rider->station_hub_id, 'destination_area_id' => $old->area_id,
            'delivery_attempts' => $failures,
        ])->fresh();

        return [$provider, $old, $replacement, $parcel];
    }

    public function test_first_failure_allows_one_retry_and_second_failure_allows_return_only(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $service = app(SellerOrderStatusService::class);
        $stale = $parcel->fresh();
        $parcel = $service->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $this->assertSame(1, $parcel->delivery_attempts);
        $service->transition($stale, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $this->assertSame(1, $parcel->fresh()->delivery_attempts);
        $this->assertSame(1, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());

        $this->actingAs($provider, 'logistics')->get(route('logistics.delivery-assignments.index'))
            ->assertOk()->assertSee('Schedule final attempt');
        $parcel->forceFill(['returned_to_station_at' => now()])->save(); // Station Hub scanned it back in.
        $this->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id, 'reason' => 'Replacement Rider selected for the final attempt.'])->assertSessionHas('success');
        $this->assertSame(1, $parcel->fresh()->delivery_attempts);
        $parcel = $service->transition($parcel->fresh(), OrderStatus::OutForDelivery, actor: OrderActor::Rider);
        $parcel = $service->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $this->assertSame(2, $parcel->delivery_attempts);
        $this->assertFalse(SellerOrderStatusService::canRetryDelivery($parcel));
        $this->get(route('logistics.delivery-assignments.index'))->assertOk()
            ->assertSee('Two attempts exhausted. Physical return required; verified Seller receipt is needed.')
            ->assertDontSee(route('logistics.delivery-assignments.reschedule', $parcel), false);
        $this->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $old->id])->assertSessionHas('error');
        $this->assertSame($replacement->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->post(route('logistics.delivery-assignments.return', $parcel))->assertSessionHas('success');
        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->fresh()->status);
        $this->assertSame(ParcelReturnStatus::Requested, $parcel->fresh()->parcelReturn->status);
        $this->assertSame(2, $parcel->fresh()->delivery_attempts);
    }

    // Custody-safe: after a failed attempt the parcel is still with that Rider.
    public function test_final_attempt_goes_to_another_rider_only_after_scan_verified_station_receipt(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $parcel->forceFill(['tracking_number' => 'TRK-FAILED-RETURN-1'])->save();
        $parcel = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $this->actingAs($provider, 'logistics');

        // Blocked for another Rider, directly and through the service.
        $this->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id])
            ->assertSessionHas('error', DeliveryAssignmentsController::STILL_WITH_RIDER_MESSAGE);
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($parcel->fresh(), $provider, $replacement->id, rescheduled: true));
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->get(route('logistics.delivery-assignments.index'))->assertOk()->assertSee('scanned back in at the Station Hub');

        // A receipt without the scan token is refused.
        $this->post(route('logistics.delivery-assignments.receive-back', $parcel))->assertSessionHasErrors();
        $this->assertNull($parcel->fresh()->returned_to_station_at);

        // Scan at the Station Hub, then receive it back with the issued token.
        $scan = $this->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->assertJsonPath('context', 'failed_return_receipt')->json();
        $action = collect($scan['actions'])->firstWhere('key', 'receive_failed_return');
        $this->post($action['url'], ['verification_token' => $action['verification_token']])
            ->assertRedirect()->assertSessionHas('success');
        $parcel->refresh();
        $this->assertNotNull($parcel->returned_to_station_at);
        $this->assertTrue($parcel->isBackAtStationAfterFailure());
        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->status);
        $this->assertTrue(OperationsAuditLog::query()->where('action', 'failed_parcel_received_back')->exists());

        // Now the final attempt may go to another Rider.
        $this->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id])
            ->assertSessionHas('success');
        $this->assertSame($replacement->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }

    public function test_the_same_rider_can_take_the_final_attempt_without_a_station_scan(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $parcel = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);

        // Auto-assign keeps the Rider who still holds the parcel.
        $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.reschedule', $parcel))
            ->assertSessionHas('success');
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }

    public function test_the_reason_for_a_final_attempt_is_saved_and_logged(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $parcel = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $parcel->forceFill(['returned_to_station_at' => now()])->save(); // Station Hub scanned it back in.
        $url = route('logistics.delivery-assignments.reschedule', $parcel);

        $this->actingAs($provider, 'logistics')
            ->post($url, ['courier_id' => $replacement->id, 'reason' => str_repeat('a', 501)])
            ->assertSessionHasErrors('reason');
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);

        $this->post($url, ['courier_id' => $replacement->id, 'reason' => 'Buyer asked for an afternoon rider.'])
            ->assertSessionHas('success');

        $log = OperationsAuditLog::query()->where('action', 'rider_chosen_manually')->sole();
        $this->assertSame('Buyer asked for an afternoon rider.', $log->reason);
        $this->assertSame($replacement->rider->fullName(), $log->new_values['delivery_rider']);

        $notes = json_decode($parcel->deliveryAssignments()->where('role', 'delivery')->latest('id')->first()->notes, true);
        $this->assertSame('Buyer asked for an afternoon rider.', $notes['reason']);
        $this->get(route('logistics.delivery-assignments.index'))->assertOk()->assertSee('Buyer asked for an afternoon rider.');
    }

    public function test_a_final_attempt_with_no_reason_still_works(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $parcel = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
        $parcel->forceFill(['returned_to_station_at' => now()])->save(); // Station Hub scanned it back in.

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id])
            ->assertSessionHas('success');

        $this->assertNull(OperationsAuditLog::query()->where('action', 'rider_chosen_manually')->sole()->reason);
    }

    public static function invalidRetryCounts(): array
    {
        return ['missing failure history' => [0], 'second failure' => [2], 'legacy excess failures' => [3]];
    }

    #[DataProvider('invalidRetryCounts')]
    public function test_retry_rejected_without_changing_assignment_or_history(int $failures): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::DeliveryFailed, $failures);
        $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $replacement->id, 'reason' => 'Replacement Rider selected for the final attempt.'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($parcel, $provider, $replacement->id, rescheduled: true));
        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->fresh()->status);
        $this->assertSame($failures, $parcel->fresh()->delivery_attempts);
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertTrue($replacement->fresh()->is_available);
        $this->assertSame(0, $parcel->deliveryAssignments()->count());
        Notification::assertNothingSent();
    }

    public function test_status_service_itself_prevents_third_attempt(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::DeliveryFailed, 2);
        try {
            app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::AssignedToRider, actor: OrderActor::Logistics);
            $this->fail('A third delivery attempt must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('two total attempts', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->fresh()->status);
        $this->assertSame(0, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_retry_checks_locked_failure_count_instead_of_stale_caller(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::DeliveryFailed, 1);
        $parcel->fresh()->forceFill(['delivery_attempts' => 2])->save();
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($parcel, $provider, $replacement->id, rescheduled: true));
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(2, $parcel->fresh()->delivery_attempts);
        Notification::assertNothingSent();
    }

    public function test_successful_delivery_does_not_increment_failed_attempt_counter(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture();
        $result = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Delivered, actor: OrderActor::Rider);
        $this->assertSame(0, $result->delivery_attempts);
        $this->assertSame(OrderStatus::Delivered, $result->status);
    }

    public function test_reassignment_cannot_restart_an_exhausted_delivery(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::OutForDelivery, 2);
        $this->assertNull(app(CourierAssignmentService::class)->reassign($parcel, $provider, 'delivery', $replacement->id, 'Rider unavailable'));
        $this->assertSame($old->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(2, $parcel->fresh()->delivery_attempts);
        Notification::assertNothingSent();
    }

    public function test_successful_second_attempt_retains_one_recorded_failure(): void
    {
        Notification::fake();
        [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::OutForDelivery, 1);
        $result = app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Delivered, actor: OrderActor::Rider);
        $this->assertSame(1, $result->delivery_attempts);
        $this->assertSame(OrderStatus::Delivered, $result->status);
    }

    public function test_rider_ui_distinguishes_final_retry_from_return_required(): void
    {
        foreach ([1 => 'Waiting for Logistics to schedule the final attempt or return the parcel.', 2 => 'Two attempts exhausted. Logistics must arrange return to seller.'] as $failures => $message) {
            [$provider, $old, $replacement, $parcel] = $this->fixture(OrderStatus::DeliveryFailed, $failures);
            $this->actingAs($old->rider, 'rider')->get(route('rider.deliveries.index'))->assertOk()->assertSee($message);
        }
    }
}
