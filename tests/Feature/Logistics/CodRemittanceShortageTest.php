<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CodRemittanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** I-05: actual COD cash, shortages, responsible Rider and later manual recovery. */
class CodRemittanceShortageTest extends TestCase
{
    use RefreshDatabase;

    private LogisticsProvider $provider;

    private Rider $rider;

    private CodRemittance $remittance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->rider = Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
        ]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'rider_id' => $this->rider->id,
        ]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'delivery_courier_id' => $courier->id,
        ]);
        $sellerOrder->forceFill(['status' => OrderStatus::Delivered->value, 'shipping_fee_minor' => 500, 'subtotal_minor' => 8000])->save();
        $payment = Payment::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'amount_minor' => 8500,
            'method' => PaymentMethod::Cod->value,
            'status' => PaymentStatus::Paid->value,
            'collected_amount_minor' => 8500,
            'collected_at' => now(),
            'paid_at' => now(),
        ]);

        $this->remittance = new CodRemittance;
        $this->remittance->forceFill([
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'courier_id' => $courier->id,
            'logistics_provider_id' => $this->provider->id,
            'amount_minor' => 8500,
            'status' => CodRemittance::PENDING_REMITTANCE,
        ])->save();
    }

    public function test_short_receipt_records_actual_cash_shortage_rider_and_reason_without_settlement(): void
    {
        $confirmed = app(CodRemittanceService::class)
            ->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Rider did not hand over the full collection.');

        $this->assertTrue($confirmed);
        $remittance = $this->remittance->fresh();
        $this->assertSame(CodRemittance::PARTIALLY_REMITTED, $remittance->status);
        $this->assertSame(6000, $remittance->received_amount_minor);
        $this->assertSame(2500, $remittance->shortage_minor);
        $this->assertSame(2500, $remittance->outstandingShortageMinor());
        $this->assertSame($this->rider->id, (int) $remittance->shortage_rider_id);
        $this->assertSame('Rider did not hand over the full collection.', $remittance->shortage_reason);
        $this->assertSame(0, $remittance->shortage_recovered_minor);
        $this->assertNotSame($remittance->amount_minor, $remittance->received_amount_minor, 'missing money is never marked as received');
        $this->assertSame(0, SellerSettlement::query()->count(), 'no settlement is prepared for missing cash');
    }

    public function test_short_receipt_requires_the_responsible_rider_and_a_reason(): void
    {
        $service = app(CodRemittanceService::class);

        try {
            $service->confirmReceipt($this->remittance, $this->provider, 6000, null, 'Short.');
            $this->fail('A shortage without a responsible rider was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shortage_rider_id', $exception->errors());
        }

        $other = Rider::factory()->create(['status' => Rider::STATUS_APPROVED]);
        try {
            $service->confirmReceipt($this->remittance, $this->provider, 6000, $other->id, 'Rider did not hand over the full collection.');
            $this->fail('A rider from another company was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shortage_rider_id', $exception->errors());
        }

        $this->assertSame(CodRemittance::PENDING_REMITTANCE, $this->remittance->fresh()->status);
    }

    public function test_later_manual_recovery_restores_received_cash_and_prepares_one_settlement(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Rider did not hand over the full collection.');
        $this->assertSame(0, SellerSettlement::query()->count());

        $service->recordShortageRecovery($this->remittance->fresh(), $this->provider, 2500, 'PAYROLL-DEDUCTION-1', 'Recovered from the rider payroll.');

        $remittance = $this->remittance->fresh();
        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $remittance->status);
        $this->assertSame(8500, $remittance->received_amount_minor);
        $this->assertSame(2500, $remittance->shortage_recovered_minor);
        $this->assertSame(0, $remittance->outstandingShortageMinor());
        $this->assertSame('PAYROLL-DEDUCTION-1', $remittance->shortage_recovery_reference);
        $this->assertSame(1, SellerSettlement::query()->count(), 'settlement is prepared exactly once after full recovery');

        try {
            $service->recordShortageRecovery($remittance, $this->provider, 100, 'PAYROLL-DEDUCTION-2');
            $this->fail('A second recovery was accepted after the shortage was already closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remittance', $exception->errors());
        }
        $this->assertSame(1, SellerSettlement::query()->count());
    }

    public function test_recovery_cannot_exceed_the_outstanding_shortage(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Rider did not hand over the full collection.');

        try {
            $service->recordShortageRecovery($this->remittance->fresh(), $this->provider, 3000, 'OVER-RECOVERY');
            $this->fail('A recovery larger than the shortage was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recovered_amount', $exception->errors());
        }

        $this->assertSame(6000, $this->remittance->fresh()->received_amount_minor);
    }

    public function test_full_receipt_still_prepares_a_settlement_once(): void
    {
        $service = app(CodRemittanceService::class);
        $this->assertTrue($service->confirmReceipt($this->remittance, $this->provider, 8500));
        $this->assertFalse($service->confirmReceipt($this->remittance->fresh(), $this->provider, 8500), 'a repeated confirmation changes nothing');

        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $this->remittance->fresh()->status);
        $this->assertSame(0, $this->remittance->fresh()->shortage_minor);
        $this->assertSame(1, SellerSettlement::query()->count());
    }

    public function test_another_provider_cannot_record_a_shortage_recovery(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Rider did not hand over the full collection.');
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);

        $this->expectException(HttpException::class);
        $service->recordShortageRecovery($this->remittance->fresh(), $other, 2500, 'OTHER-PROVIDER');
    }
}
