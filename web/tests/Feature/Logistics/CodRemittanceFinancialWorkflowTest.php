<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\CodRemittance;
use App\Models\Courier;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\SellerSettlement;
use App\Models\Voucher;
use App\Services\CodRemittanceService;
use App\Services\CommissionFinalizationService;
use App\Services\RiderOperationsService;
use App\Services\SellerSettlementService;
use App\Services\VoucherFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CodRemittanceFinancialWorkflowTest extends TestCase
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

    /** @test */
    public function test_authorized_logistics_confirms_exact_cod_receipt(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $this->assertCount(1, CodRemittance::all());
        $remittance = CodRemittance::first();
        $this->assertSame(CodRemittance::PENDING_REMITTANCE, $remittance->status);
        $this->assertSame(8500, $remittance->amount_minor);

        $service = app(CodRemittanceService::class);
        $confirmed = $service->confirmReceipt($remittance, $this->provider, 8500);

        $this->assertTrue($confirmed);
        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $remittance->fresh()->status);
        $this->assertSame($this->provider->id, $remittance->fresh()->confirmed_by_id);
        $this->assertNotNull($remittance->fresh()->confirmed_at);
        $this->assertSame(8500, $remittance->fresh()->received_amount_minor);
    }

    /** @test */
    public function test_another_logistics_provider_cannot_confirm(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $remittance = CodRemittance::first();

        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $service = app(CodRemittanceService::class);
        $this->expectException(HttpException::class);
        $service->confirmReceipt($remittance, $otherProvider, 8500);
    }

    /** @test */
    public function test_duplicate_confirmation_does_not_create_duplicate_records(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $remittance = CodRemittance::first();
        $service = app(CodRemittanceService::class);

        $confirmed = $service->confirmReceipt($remittance, $this->provider, 8500);
        $this->assertTrue($confirmed);
        $this->assertCount(1, CodRemittance::all());

        $confirmedAgain = $service->confirmReceipt($remittance, $this->provider, 8500);
        $this->assertFalse($confirmedAgain);
        $this->assertCount(1, CodRemittance::all());
        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $remittance->fresh()->status);
    }

    /** @test */
    public function test_confirmed_remittance_creates_one_settlement(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $remittance = CodRemittance::first();
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($remittance, $this->provider, 8500);

        $this->assertCount(1, SellerSettlement::all());
        $settlement = SellerSettlement::first();

        $this->assertSame($this->sellerOrder->id, $settlement->seller_order_id);
        $this->assertSame($remittance->id, $settlement->cod_remittance_id);
        $this->assertSame($this->payment->id, $settlement->payment_id);
        $this->assertSame($this->seller->id, $settlement->seller_id);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->status);
        $this->assertSame(8500, $settlement->collected_amount_minor);
        $this->assertSame(500, $settlement->shipping_allocation_minor);
        $this->assertSame(SellerSettlement::COMMISSION_FINALIZATION_MISSING, $settlement->pending_reason);
        $this->assertNull($settlement->commission_bps);
        $this->assertNull($settlement->seller_payout_minor);
    }

    /** @test */
    public function test_completed_order_finalizes_10_percent_commission(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $remittance = CodRemittance::first();
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($remittance, $this->provider, 8500);

        $this->sellerOrder->forceFill(['status' => OrderStatus::Completed->value])->save();

        $commissionService = app(CommissionFinalizationService::class);
        $commissionService->finalize($this->sellerOrder->fresh());

        $order = $this->sellerOrder->fresh();

        $this->assertSame(1000, $order->commission_bps);
        $this->assertSame(1000, $order->commission_minor);
        $this->assertNotNull($order->commission_finalized_at);

        $this->assertSame(7000, $order->seller_net_minor);

        $settlement = SellerSettlement::where('seller_order_id', $order->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame(1000, $settlement->commission_bps);
        $this->assertSame(1000, $settlement->commission_minor);
        $this->assertSame(7000, $settlement->seller_payout_minor);
        $this->assertNotNull($settlement->financials_finalized_at);
        $this->assertNull($settlement->pending_reason);
    }

    /** @test */
    public function test_seller_funded_and_marketplace_funded_voucher_allocations(): void
    {
        $funding = app(VoucherFundingService::class);

        $sellerFundedSeller = Seller::factory()->create(['status' => 'approved']);
        $sellerFundedOrder = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'discount_minor' => 2000,
        ]);
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $sellerFundedOrder->id,
            'seller_id' => $sellerFundedSeller->id,
        ])->fresh();
        $sellerOrder->forceFill([
            'status' => OrderStatus::Completed->value,
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 500,
            'voucher_discount_minor' => 2000,
            'voucher_funding_source' => Voucher::FUNDING_SELLER,
        ])->save();

        $allocation = $funding->allocationFor($sellerOrder);
        $this->assertSame(2000, $allocation['discount_minor']);
        $this->assertSame(2000, $allocation['seller_minor']);
        $this->assertSame(0, $allocation['marketplace_minor']);
        $this->assertNull($allocation['pending_reason']);

        $marketplaceFundedSeller = Seller::factory()->create(['status' => 'approved']);
        $marketplaceFundedOrder = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'discount_minor' => 2000,
        ]);
        $marketplaceOrder = SellerOrder::factory()->create([
            'order_id' => $marketplaceFundedOrder->id,
            'seller_id' => $marketplaceFundedSeller->id,
        ])->fresh();
        $marketplaceOrder->forceFill([
            'status' => OrderStatus::Completed->value,
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 500,
            'voucher_discount_minor' => 2000,
            'voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
        ])->save();

        $allocation = $funding->allocationFor($marketplaceOrder);
        $this->assertSame(2000, $allocation['discount_minor']);
        $this->assertSame(0, $allocation['seller_minor']);
        $this->assertSame(2000, $allocation['marketplace_minor']);
        $this->assertNull($allocation['pending_reason']);
    }

    /** @test */
    public function test_payout_blocked_if_financial_data_is_incomplete(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 8500,
        ]);

        $remittance = CodRemittance::first();
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($remittance, $this->provider, 8500);

        $settlement = SellerSettlement::first();
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->status);
        $this->assertSame(SellerSettlement::COMMISSION_FINALIZATION_MISSING, $settlement->pending_reason);

        $admin = Admin::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $settlementService = app(SellerSettlementService::class);
        $this->expectException(ValidationException::class);
        $settlementService->confirmPayout(
            $settlement,
            $admin,
            7000,
            'PAYOUT-REF-001'
        );
    }
}
