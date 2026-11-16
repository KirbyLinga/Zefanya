<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\PlatformRefundExpense;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\SellerDebt;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\ParcelReturnService;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Audit 1.13 follow-up: platform-funded exception refunds on independent InnoDB connections. */
class PlatformRefundConcurrencyTest extends TestCase
{
    use UsesLogisticsDatabase;

    private const PLATFORM = ['refund_exception_basis' => 'separate_approval', 'refund_exception_note' => 'Goodwill refund approved by the finance lead, FIN-77.',
        'refund_funding_party' => 'platform', 'refund_funding_note' => 'Zefanya absorbs this goodwill refund per FIN-77.'];

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    /** A pending buyer return on ₱85 collected COD: a refund needs a documented exception. */
    private function fixture(): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $rider = Rider::factory()->create(['logistics_provider_id' => $provider->id, 'status' => Rider::STATUS_APPROVED]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id, 'rider_id' => $rider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['delivery_courier_id' => $courier->id, 'subtotal_minor' => 8000]);
        $order->forceFill(['shipping_fee_minor' => 500, 'commission_bps' => 1000, 'commission_minor' => 800, 'seller_net_minor' => 7200])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 8500]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 8500])->save();
        $remittance = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 8500, 'status' => CodRemittance::PENDING_REMITTANCE]);
        $remittance->snapshotAllocation();
        $remittance->save();
        $return = (new ParcelReturn)->forceFill(['seller_order_id' => $order->id, 'status' => ParcelReturnStatus::Requested,
            'reason' => 'Item arrived damaged', 'requested_at' => now()->subDay(), 'seller_response_due_at' => now()->addDays(2),
            'financial_review_required' => true, 'delivery_attempts' => 0]);
        $return->save();

        return ['order' => $order, 'payment' => $payment, 'remittance' => $remittance, 'return' => $return,
            'admin' => Admin::factory()->create(['role' => 'super_admin']), 'other' => Admin::factory()->create(['role' => 'super_admin'])];
    }

    private function refund(array $f, Admin $admin, string $amount = '85.00', array $extra = []): array
    {
        return ['action' => 'return_refund', 'admin' => $admin->id, 'return' => $f['return']->id, 'amount' => $amount,
            'extra' => $extra + self::PLATFORM + ($amount === '85.00' ? [] : ['refund_reason' => 'Partial goodwill refund agreed with the buyer.'])];
    }

    private function noSqlErrors(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors'], 'Unexpected MySQL errors: '.json_encode($result));
        }
    }

    /** Commission, Seller net, remittance (including its 40/60 snapshot) and payment, as stored. */
    private function untouchable(array $f): array
    {
        return [$f['order']->fresh()->only(['commission_bps', 'commission_minor', 'seller_net_minor', 'shipping_fee_minor']),
            collect($f['remittance']->fresh()->getAttributes())->except(['updated_at'])->all(),
            $f['payment']->fresh()->only(['status', 'amount_minor', 'collected_amount_minor'])];
    }

    private function noSellerOrLogisticsLiability(): void
    {
        $this->assertSame(0, SellerDebt::query()->count());
        $this->assertSame(0, LogisticsReturnCharge::query()->count());
    }

    /** A: two Admins submit the same platform-funded refund at once. */
    public function test_duplicate_platform_refunds_record_one_refund_and_one_expense(): void
    {
        $f = $this->fixture();
        $before = $this->untouchable($f);
        $results = Race::run($this->refund($f, $f['admin']) + ['hold' => 'parcel'], $this->refund($f, $f['other']) + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']], json_encode($results));
        $return = $f['return']->fresh();
        $this->assertSame([8500, ParcelReturn::FAULT_PLATFORM, (int) $f['admin']->id], [$return->refund_amount_minor, $return->fault_party, (int) $return->fault_set_by_admin_id]);
        $this->assertSame(1, $return->refundHistory()->count());
        $expense = PlatformRefundExpense::query()->sole();
        $this->assertSame([8500, (int) $return->refundHistory()->sole()->id], [$expense->amount_minor, (int) $expense->refund_event_id]);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_fault_set')->count());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'platform_refund_expense_recorded')->count());
        $this->noSellerOrLogisticsLiability();
        $this->assertSame($before, $this->untouchable($f));
        $this->assertSame(0, app(PlatformFinanceService::class)->position()['refundsToRecordCount']);
    }

    /** B: two different partial amounts race; only the first commits and the expense matches it. */
    public function test_competing_partial_refunds_commit_only_one_amount(): void
    {
        $f = $this->fixture();
        $results = Race::run($this->refund($f, $f['admin'], '60.00') + ['hold' => 'parcel'], $this->refund($f, $f['other'], '50.00') + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']]);
        $this->assertSame(6000, $f['return']->fresh()->refund_amount_minor);
        $this->assertSame([6000], PlatformRefundExpense::query()->pluck('amount_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertLessThanOrEqual(8500, (int) PlatformRefundExpense::query()->sum('amount_minor'));
    }

    /** C: a void racing a duplicate void or a stale replacement leaves exactly one reversal. */
    #[TestWith(['void'])]
    #[TestWith(['replacement'])]
    public function test_void_racing_a_second_void_or_stale_replacement_reverses_once(string $second): void
    {
        $f = $this->fixture();
        app(ParcelReturnService::class)->recordRefund($f['return'], $f['admin'], 'GCASH-1', 8500, 'Buyer GCash', today()->toDateString(), null, null, 0,
            'separate_approval', 'Goodwill refund approved by the finance lead, FIN-77.', 'platform', 'Zefanya absorbs this goodwill refund per FIN-77.');
        $revision = (int) $f['return']->refundHistory()->max('id');
        $void = ['action' => 'return_refund_void', 'admin' => $f['admin']->id, 'return' => $f['return']->id, 'refund_revision' => $revision];
        $b = $second === 'void'
            ? ['action' => 'return_refund_void', 'admin' => $f['other']->id, 'return' => $f['return']->id, 'refund_revision' => $revision]
            : $this->refund($f, $f['other'], '85.00', ['refund_revision' => $revision]);
        $results = Race::run($void + ['hold' => 'parcel'], $b + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']], json_encode($results));
        $return = $f['return']->fresh();
        $this->assertNull($return->refunded_at);
        $rows = PlatformRefundExpense::query()->orderBy('id')->get();
        $this->assertSame([['expense', 8500], ['reversal', 8500]], $rows->map(fn ($r) => [$r->entry_type, (int) $r->amount_minor])->all());
        $this->assertSame((int) $rows[0]->id, (int) $rows[1]->reverses_id);
        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame([1, 8500], [$position['refundsToRecordCount'], $position['refundsToRecordMinor']], 'The voided authorized exception is owed again.');
        $this->noSellerOrLogisticsLiability();
    }

    /** D: InnoDB rolls back the refund, payer decision, history and audit when the expense or its audit fails. */
    #[TestWith(['expense'])]
    #[TestWith(['audit'])]
    public function test_injected_expense_or_audit_failure_rolls_back_everything_on_mysql(string $failAt): void
    {
        $f = $this->fixture();
        $before = [$this->untouchable($f), $f['return']->fresh()->only(['refunded_at', 'fault_party', 'fault_note', 'fault_set_by_admin_id']),
            $f['return']->events()->count(), OperationsAuditLog::query()->count()];
        if ($failAt === 'expense') {
            PlatformRefundExpense::creating(fn () => throw new \RuntimeException('Simulated expense write failure.'));
        } else {
            OperationsAuditLog::creating(function (OperationsAuditLog $log): void {
                if ($log->action === 'platform_refund_expense_recorded') {
                    throw new \RuntimeException('Simulated audit write failure.');
                }
            });
        }

        try {
            app(ParcelReturnService::class)->recordRefund($f['return'], $f['admin'], 'GCASH-1', 8500, 'Buyer GCash', today()->toDateString(), null, null, 0,
                'separate_approval', 'Goodwill refund approved by the finance lead, FIN-77.', 'platform', 'Zefanya absorbs this goodwill refund per FIN-77.');
            $this->fail('The injected failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('Simulated', $exception->getMessage());
        }

        $this->assertSame($before, [$this->untouchable($f), $f['return']->fresh()->only(['refunded_at', 'fault_party', 'fault_note', 'fault_set_by_admin_id']),
            $f['return']->events()->count(), OperationsAuditLog::query()->count()]);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
    }

    /** E: an Admin payer decision and a platform-funded refund race; no mixed liability. */
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_payer_decision_and_platform_refund_never_mix_liability(bool $decisionFirst): void
    {
        $f = $this->fixture();
        $decision = ['action' => 'return_fault', 'admin' => $f['other']->id, 'return' => $f['return']->id, 'party' => ParcelReturn::FAULT_LOGISTICS];
        $results = $decisionFirst
            ? Race::run($decision + ['hold' => 'parcel'], $this->refund($f, $f['admin']) + ['watch' => 'parcel'])
            : Race::run($this->refund($f, $f['admin']) + ['hold' => 'parcel'], $decision + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']], json_encode($results));
        $return = $f['return']->fresh();
        if ($decisionFirst) {
            $this->assertSame([ParcelReturn::FAULT_LOGISTICS, null], [$return->fault_party, $return->refunded_at]);
            $this->assertSame(0, PlatformRefundExpense::query()->count());
        } else {
            $this->assertSame([ParcelReturn::FAULT_PLATFORM, 8500], [$return->fault_party, $return->refund_amount_minor]);
            $this->assertSame(1, PlatformRefundExpense::query()->count());
        }
        $this->noSellerOrLogisticsLiability();
    }
}
