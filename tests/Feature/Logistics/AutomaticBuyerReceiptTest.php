<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\IncidentEvents;
use App\Models\Incidents;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelReturn;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\SellerSettlement;
use App\Models\StatusHistory;
use App\Models\Voucher;
use App\Services\AutomaticBuyerReceiptService;
use App\Services\RiderOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutomaticBuyerReceiptTest extends TestCase
{
    use RefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Rider $rider;

    private Seller $seller;

    private Buyer $buyer;

    private Order $order;

    private SellerOrder $sellerOrder;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // Province Hub + Station Hub so the rider is operational.
        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Test Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Test Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Santo Rosario',
            'street' => '1 Test Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        $this->rider = Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
            'station_hub_id' => $station->id,
        ]);

        $this->courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'rider_id' => $this->rider->id,
            'is_available' => true,
        ]);

        $this->order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'discount_minor' => 2000,
        ]);

        $this->sellerOrder = SellerOrder::factory()->create([
            'order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'delivery_courier_id' => $this->courier->id,
        ])->fresh();
        $this->sellerOrder->forceFill([
            'status' => OrderStatus::OutForDelivery->value,
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 500,
            'voucher_discount_minor' => 2000,
            'voucher_funding_source' => Voucher::FUNDING_SELLER,
        ])->save();

        $this->payment = Payment::factory()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'amount_minor' => 8500,
            'method' => PaymentMethod::Cod->value,
            'status' => PaymentStatus::Pending->value,
        ]);
    }

    /** Helper: transition order to Delivered with a delivery StatusHistory at $deliveredAt. */
    private function deliverAt(CarbonImmutable $deliveredAt): void
    {
        $this->sellerOrder->forceFill(['status' => OrderStatus::Delivered->value])->save();

        StatusHistory::query()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'from_status' => OrderStatus::OutForDelivery->value,
            'to_status' => OrderStatus::Delivered->value,
            'changed_by' => 'Rider',
            'note' => 'Parcel delivered to buyer.',
            'actor_type' => 'rider',
            'actor_id' => $this->rider->id,
            'source' => 'web',
            'created_at' => $deliveredAt,
            'updated_at' => $deliveredAt,
        ]);
    }

    /** Helper: complete automatic receipt for this seller order. */
    private function autoComplete(): bool
    {
        $cutoff = CarbonImmutable::now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS);
        $service = app(AutomaticBuyerReceiptService::class);

        return $service->complete((int) $this->sellerOrder->id, $cutoff);
    }

    /** @test */
    public function test_delivered_older_than_72_hours_is_completed_automatically(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        $result = $this->autoComplete();

        $this->assertTrue($result);
        $this->assertSame(OrderStatus::Completed, $this->sellerOrder->fresh()->status);
        $this->assertNotNull($this->sellerOrder->fresh()->commission_finalized_at);
        $this->assertSame(1000, $this->sellerOrder->fresh()->commission_bps);
        $this->assertSame(1000, $this->sellerOrder->fresh()->commission_minor);
        $this->assertSame(7000, $this->sellerOrder->fresh()->seller_net_minor);
    }

    /** @test */
    public function test_delivered_less_than_72_hours_remains_delivered(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(71));

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
        $this->assertNull($this->sellerOrder->fresh()->commission_finalized_at);
    }

    /** @test */
    public function test_delivered_exactly_72_hours_is_eligible(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS));

        $result = $this->autoComplete();

        $this->assertTrue($result);
        $this->assertSame(OrderStatus::Completed, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_open_incident_prevents_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        Incidents::query()->create([
            'incident_seller_order_id' => $this->sellerOrder->id,
            'reporter_id' => $this->rider->id,
            'reporter_type' => 'rider',
            'type' => 'lost',
            'description' => 'Parcel reported lost.',
            'status' => ParcelIncidentStatus::Open->value,
            'happened_at' => now(),
        ]);

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_terminal_lost_resolution_prevents_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        $incident = Incidents::query()->create([
            'incident_seller_order_id' => $this->sellerOrder->id,
            'reporter_id' => $this->rider->id,
            'reporter_type' => 'rider',
            'type' => 'lost',
            'description' => 'Parcel reported lost.',
            'status' => ParcelIncidentStatus::Resolved->value,
            'happened_at' => now(),
        ]);

        IncidentEvents::query()->create([
            'incident_id' => $incident->id,
            'event' => 'resolved',
            'label' => 'Closed as lost.',
            'detail' => 'CLOSE_LOST',
            'occurred_at' => now(),
        ]);

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_physical_return_prevents_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        ParcelReturn::query()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'origin_provider_id' => $this->provider->id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Customer requested return',
            'requested_at' => now(),
        ]);

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_unresolved_handoff_exception_prevents_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        ParcelHandoffException::query()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'origin_provider_id' => $this->provider->id,
            'status' => 'OPEN',
            'reason' => 'No serviceable partner.',
        ]);

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_missing_delivery_timestamp_prevents_completion(): void
    {
        $this->sellerOrder->forceFill(['status' => OrderStatus::Delivered->value])->save();

        // No StatusHistory at all for the Delivered transition.
        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_ambiguous_delivery_timestamp_prevents_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        // Add a second, undated Delivered history record — makes the delivery ambiguous.
        StatusHistory::query()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'from_status' => OrderStatus::OutForDelivery->value,
            'to_status' => OrderStatus::Delivered->value,
            'changed_by' => 'System',
            'note' => 'Duplicate delivery record.',
            'actor_type' => 'system',
            'actor_id' => null,
            'source' => 'system',
            'created_at' => null,
            'updated_at' => now(),
        ]);

        $result = $this->autoComplete();

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    /** @test */
    public function test_repeated_execution_does_not_duplicate_completion(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        $first = $this->autoComplete();
        $this->assertTrue($first);
        $this->assertSame(OrderStatus::Completed, $this->sellerOrder->fresh()->status);

        $second = $this->autoComplete();
        $this->assertFalse($second);
        $this->assertSame(OrderStatus::Completed, $this->sellerOrder->fresh()->status);

        // No duplicate commission records.
        $this->assertSame(1000, $this->sellerOrder->fresh()->commission_bps);
        $this->assertSame(1000, $this->sellerOrder->fresh()->commission_minor);
        $this->assertSame(7000, $this->sellerOrder->fresh()->seller_net_minor);
        $this->assertNotNull($this->sellerOrder->fresh()->commission_finalized_at);
    }

    /** @test */
    public function test_successful_completion_records_system_source(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        $this->autoComplete();

        $history = StatusHistory::query()
            ->where('seller_order_id', $this->sellerOrder->id)
            ->where('to_status', OrderStatus::Completed->value)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame(OrderActor::System->value, $history->actor_type);
        $this->assertSame('system', $history->source);
        $this->assertSame('System', $history->changed_by);
    }

    /** @test */
    public function test_completed_order_creates_settlement_with_finalized_commission(): void
    {
        $this->deliverAt(CarbonImmutable::now()->subHours(73));

        // First complete delivery to create COD remittance and settlement.
        $operations = app(RiderOperationsService::class);
        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        // Verify settlement exists in pending state.
        $settlement = SellerSettlement::where('seller_order_id', $this->sellerOrder->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->status);
        $this->assertNull($settlement->commission_bps);

        // Now auto-complete the receipt (72+ hours since delivery).
        $this->deliverAt(CarbonImmutable::now()->subHours(73));
        $this->autoComplete();

        // Settlement should now have finalized commission.
        $settlement->refresh();
        $this->assertSame(1000, $settlement->commission_bps);
        $this->assertSame(1000, $settlement->commission_minor);
        $this->assertSame(7000, $settlement->seller_payout_minor);
        $this->assertNotNull($settlement->financials_finalized_at);
        $this->assertNull($settlement->pending_reason);
    }
}
