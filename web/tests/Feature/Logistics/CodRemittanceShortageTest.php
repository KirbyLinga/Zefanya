<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Rider\RiderCashService;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
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
        $this->withoutVite();

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

    public function test_duplicate_partial_recovery_reference_cannot_credit_the_same_shortage_again_even_after_another_recovery(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        $service->recordShortageRecovery($this->remittance, $this->provider, 500, 'RECOVERY-A');
        $service->recordShortageRecovery($this->remittance, $this->provider, 500, 'RECOVERY-B');
        try {
            $service->recordShortageRecovery($this->remittance, $this->provider, 500, ' RECOVERY-A ');
            $this->fail('A repeated recovery reference credited cash twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference', $exception->errors());
        }
        $this->assertSame(7000, $this->remittance->fresh()->received_amount_minor);
        $this->assertSame(1500, $this->remittance->fresh()->outstandingShortageMinor());
        $this->assertSame(2, OperationsAuditLog::query()->where('action', 'cod_shortage_recovered')->count());
        $this->assertSame(0, SellerSettlement::query()->count());
    }

    public function test_partial_cash_is_visible_at_both_custodians_without_becoming_ready_to_remit(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        $service->recordShortageRecovery($this->remittance, $this->provider, 500, 'PARTIAL-RECOVERY');
        $money = app(LogisticsRemittanceService::class);
        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame(2000, $position['cashWithRidersMinor']);
        $this->assertSame(6500, $position['cashAtCompaniesMinor']);
        $this->assertSame(8500, $position['outstandingCashMinor']);
        $this->assertSame(2000, $money->totals($this->provider)['rider']['codMinor']);
        $this->assertSame(0, $money->readyParcels($this->provider)->count());
        $this->assertSame(0, $position['cashSentAwaitingMinor']);
        $this->assertSame(6500, $money->parcelBalances($this->provider)[$this->remittance->id]['balanceMinor']);
        $this->assertSame(0, SellerSettlement::query()->count());
    }

    public function test_monthly_handover_counts_recovery_in_its_own_month_including_legacy_audit_values(): void
    {
        $service = app(CodRemittanceService::class);
        $this->travelTo(now()->startOfMonth()->subDays(3));
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        $service->recordShortageRecovery($this->remittance, $this->provider, 500, 'PREVIOUS-MONTH');
        $this->travelBack();
        $service->recordShortageRecovery($this->remittance, $this->provider, 1000, 'CURRENT-MONTH');
        // Legacy recovery audits contain the before/after formatted cash, without a numeric delta.
        $log = OperationsAuditLog::query()->where('action', 'cod_shortage_recovered')->latest('id')->firstOrFail();
        $values = $log->new_values;
        unset($values['recovered_amount_minor']);
        DB::table('operations_audit_logs')->where('id', $log->id)->update(['new_values' => json_encode($values)]);
        $service->recordShortageRecovery($this->remittance, $this->provider, 100, 'CURRENT-NUMERIC-AUDIT');
        $summary = app(RiderCashService::class)->summaryFor($this->remittance->courier);
        $this->assertSame(7600, $summary['handedOverMinor']);
        $this->assertSame(1100, $summary['handedOverMonthMinor']);
        $this->assertSame(900, $summary['holdingMinor']);
    }

    public function test_recovery_references_remain_reusable_on_unrelated_shortages(): void
    {
        $order = $this->remittance->sellerOrder->replicate();
        $order->forceFill(['tracking_number' => null, 'order_id' => Order::factory()->create()->id])->save();
        $payment = $this->remittance->payment->replicate();
        $payment->forceFill(['seller_order_id' => $order->id])->save();
        $other = $this->remittance->replicate();
        $other->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id])->save();
        $service = app(CodRemittanceService::class);
        foreach ([$this->remittance, $other] as $remittance) {
            $service->confirmReceipt($remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
            $service->recordShortageRecovery($remittance, $this->provider, 500, 'SHARED-BATCH-REF');
            $this->assertSame(6500, $remittance->fresh()->received_amount_minor);
        }
        $this->assertSame(2, OperationsAuditLog::query()->where('action', 'cod_shortage_recovered')->count());
    }

    public function test_refund_keeps_collection_and_partial_handover_as_separate_financial_history(): void
    {
        app(CodRemittanceService::class)->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        $before = $this->remittance->fresh()->getRawOriginal();
        $payment = $this->remittance->payment->fresh()->getRawOriginal();
        $record = ParcelReturn::query()->forceCreate(['seller_order_id' => $this->remittance->seller_order_id,
            'reason' => 'Wrong item received', 'requested_at' => now(), 'financial_review_required' => true]);
        $service = app(ParcelReturnService::class);
        $admin = Admin::factory()->create(['role' => 'admin']);
        $service->recordRefund($record, $admin, 'REFUND-WITH-CASH-OUTSTANDING', 8500, 'Buyer', today()->toDateString());
        try {
            $service->recordRefund($record, $admin, 'DUPLICATE-REFUND', 8500, 'Buyer', today()->toDateString());
            $this->fail('A duplicate refund was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }
        $this->assertSame($before, $this->remittance->fresh()->getRawOriginal());
        $this->assertSame($payment, $this->remittance->payment->fresh()->getRawOriginal());
        $this->assertSame(8500, app(PlatformFinanceService::class)->position()['outstandingCashMinor']);
        $this->assertSame(2500, $this->remittance->fresh()->outstandingShortageMinor());
        $this->assertSame(8500, $record->fresh()->refund_amount_minor);
        $this->assertSame(0, SellerDebt::query()->count());
    }

    public function test_status_only_completion_does_not_invent_cod_payment_or_refund_entitlement(): void
    {
        $payment = $this->remittance->payment;
        $payment->forceFill(['status' => PaymentStatus::Pending, 'paid_at' => null, 'collected_at' => null, 'collected_amount_minor' => null])->save();
        $order = $this->remittance->sellerOrder;
        app(SellerOrderStatusService::class)->transition($order, OrderStatus::Completed, actor: OrderActor::Buyer, actorId: $order->order->buyer_id);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $payment->fresh()->refundableCollectedMinor());
        $this->assertNull($payment->fresh()->collected_at);
        $this->assertSame(0, SellerSettlement::query()->count());
    }

    #[TestWith(['buyer'])]
    #[TestWith(['seller'])]
    #[TestWith(['rider'])]
    #[TestWith(['admin'])]
    public function test_other_roles_cannot_post_handover_or_recovery(string $guard): void
    {
        $actor = match ($guard) {
            'buyer' => Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]),
            'seller' => Seller::factory()->create(['status' => 'approved', 'email_verified_at' => now()]),
            'rider' => $this->rider,
            'admin' => Admin::factory()->create(['role' => 'super_admin']),
        };
        $before = $this->remittance->fresh()->getRawOriginal();
        $this->actingAs($actor, $guard);
        $this->postJson(route('logistics.cod-remittances.confirm', $this->remittance), ['received_amount' => '85.00'])->assertUnauthorized();
        $this->postJson(route('logistics.cod-remittances.shortage-recovery', $this->remittance), ['recovered_amount' => '10.00', 'reference' => 'FORGED'])->assertUnauthorized();
        $this->postJson(route('logistics.earnings.remit'), ['parcels' => [$this->remittance->id], 'expected_amount_minor' => 8000, 'reference' => 'FORGED'])->assertUnauthorized();
        if ($guard !== 'admin') {
            $this->postJson(route('admin.logistics-remittances.confirm', 999999), ['received_amount' => '80.00'])->assertUnauthorized();
        }
        $this->assertSame($before, $this->remittance->fresh()->getRawOriginal());
        $this->assertSame(0, OperationsAuditLog::query()->count());
    }

    public function test_http_tampering_does_not_override_authoritative_shortage_or_cash_owner(): void
    {
        $this->actingAs($this->provider, 'logistics');
        foreach (['-1', '86.00', '1e3', '0.001', '1000000000'] as $amount) {
            $this->postJson(route('logistics.cod-remittances.confirm', $this->remittance), ['received_amount' => $amount])->assertUnprocessable();
        }
        $this->postJson(route('logistics.cod-remittances.confirm', $this->remittance), [
            'received_amount' => '60.00', 'shortage_rider_id' => $this->rider->id, 'shortage_reason' => 'The rider handed over less cash.',
            'shortage_minor' => 0, 'amount_minor' => 6000, 'payment_id' => 999999, 'logistics_provider_id' => 999999, 'status' => CodRemittance::REMITTED_TO_LOGISTICS,
        ])->assertRedirect();
        $this->assertSame(2500, $this->remittance->fresh()->shortage_minor);
        $this->assertSame(8500, $this->remittance->fresh()->amount_minor);
        $this->assertSame($this->provider->id, $this->remittance->fresh()->logistics_provider_id);
        $this->assertSame(CodRemittance::PARTIALLY_REMITTED, $this->remittance->fresh()->status);
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')->postJson(route('logistics.cod-remittances.shortage-recovery', $this->remittance), ['recovered_amount' => '25.00', 'reference' => 'OTHER'])->assertForbidden();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_audit_failure_rolls_back_cash_shortage_and_settlement(bool $recovery): void
    {
        $service = app(CodRemittanceService::class);
        if ($recovery) {
            $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        }
        $before = $this->remittance->fresh()->getRawOriginal();
        $logs = OperationsAuditLog::query()->count();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'insert into "operations_audit_logs"')) {
                $armed = false;
                throw new \RuntimeException('Controlled COD audit failure.');
            }
        });
        try {
            if ($recovery) {
                $service->recordShortageRecovery($this->remittance, $this->provider, 2500, 'ROLLBACK');
            } else {
                $service->confirmReceipt($this->remittance, $this->provider, 8500);
            }
            $this->fail('Controlled audit failure did not interrupt the write.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled COD audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $this->remittance->fresh()->getRawOriginal());
        $this->assertSame($logs, OperationsAuditLog::query()->count());
        $this->assertSame(0, SellerSettlement::query()->count());
    }

    public function test_another_provider_cannot_record_a_shortage_recovery(): void
    {
        $service = app(CodRemittanceService::class);
        $service->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Rider did not hand over the full collection.');
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);

        $this->expectException(HttpException::class);
        $service->recordShortageRecovery($this->remittance->fresh(), $other, 2500, 'OTHER-PROVIDER');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_settlement_failure_rolls_back_receipt_and_its_audit(bool $recovery): void
    {
        if ($recovery) {
            app(CodRemittanceService::class)->confirmReceipt($this->remittance, $this->provider, 6000, $this->rider->id, 'Cash was missing at the first handover.');
        }
        $before = $this->remittance->fresh()->getRawOriginal();
        $logs = OperationsAuditLog::query()->count();
        $settlements = \Mockery::mock(SellerSettlementService::class);
        $settlements->shouldReceive('prepare')->once()->andThrow(new \RuntimeException('Controlled settlement failure.'));
        $service = new CodRemittanceService($settlements);
        try {
            if ($recovery) {
                $service->recordShortageRecovery($this->remittance, $this->provider, 2500, 'SETTLEMENT-ROLLBACK');
            } else {
                $service->confirmReceipt($this->remittance, $this->provider, 8500);
            }
            $this->fail('Settlement failure did not interrupt the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled settlement failure.', $exception->getMessage());
        }
        $this->assertSame($before, $this->remittance->fresh()->getRawOriginal());
        $this->assertSame($logs, OperationsAuditLog::query()->count());
        $this->assertSame(0, SellerSettlement::query()->count());
    }
}
