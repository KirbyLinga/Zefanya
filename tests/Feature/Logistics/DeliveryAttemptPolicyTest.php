<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
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
