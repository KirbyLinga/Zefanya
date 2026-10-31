<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProvidesDeliveryOtp;
use Tests\TestCase;

class CodRemittanceCreationTest extends TestCase
{
    use ProvidesDeliveryOtp;
    use RefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Rider $rider;

    private Seller $seller;

    private Buyer $buyer;

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

        $order = Order::factory()->create(['buyer_id' => $this->buyer->id]);

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

        $this->sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'delivery_courier_id' => $this->courier->id,
        ])->fresh();
        $this->sellerOrder->forceFill(['status' => OrderStatus::OutForDelivery->value])->save();

        $this->payment = Payment::factory()->create([
            'seller_order_id' => $this->sellerOrder->id,
            'amount_minor' => 44900,
            'method' => PaymentMethod::Cod->value,
            'status' => PaymentStatus::Pending->value,
        ]);
    }

    public function test_successful_cod_delivery_creates_exactly_one_pending_remittance(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'delivery_otp' => $this->deliveryOtp($this->sellerOrder->fresh()),
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 44900,
        ]);

        $this->assertCount(1, CodRemittance::all());

        $remittance = CodRemittance::first();

        $this->assertSame(CodRemittance::PENDING_REMITTANCE, $remittance->status);

        $this->assertSame($this->payment->id, $remittance->payment_id);
        $this->assertSame($this->sellerOrder->id, $remittance->seller_order_id);
        $this->assertSame($this->courier->id, $remittance->courier_id);
        $this->assertSame($this->courier->logistics_provider_id, $remittance->logistics_provider_id);
        $this->assertSame(44900, $remittance->amount_minor);

        $this->assertSame(PaymentStatus::Paid, $this->payment->fresh()->status);
        $this->assertSame(44900, $this->payment->fresh()->collected_amount_minor);
        $this->assertNotNull($this->sellerOrder->fresh()->deliveryProof);
        $this->assertSame(OrderStatus::Delivered, $this->sellerOrder->fresh()->status);
    }

    public function test_repeated_delivery_confirmation_does_not_create_duplicate_remittance(): void
    {
        $operations = app(RiderOperationsService::class);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'delivery_otp' => $this->deliveryOtp($this->sellerOrder->fresh()),
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 44900,
        ]);

        $this->assertCount(1, CodRemittance::all());
        $this->assertSame(CodRemittance::PENDING_REMITTANCE, CodRemittance::first()->status);

        $operations->completeDelivery($this->rider, $this->sellerOrder->fresh(), [
            'delivery_otp' => $this->deliveryOtp($this->sellerOrder->fresh()),
            'photo' => UploadedFile::fake()->create('proof2.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount_minor' => 44900,
        ]);

        $this->assertCount(1, CodRemittance::all());
        $this->assertSame(CodRemittance::PENDING_REMITTANCE, CodRemittance::first()->status);
        $this->assertSame($this->payment->id, CodRemittance::first()->payment_id);
    }
}
