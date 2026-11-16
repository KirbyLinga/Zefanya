<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Admin\FinanceReportService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerEarningsService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SellerPayoutConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function fixture(?Seller $seller = null): array
    {
        $seller ??= Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $parent = Order::factory()->create(['discount_minor' => 5000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 69900,
        ]);
        $order->forceFill(['shipping_fee_minor' => 6500, 'voucher_discount_minor' => 5000,
            'voucher_funding_source' => Voucher::FUNDING_SELLER])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 71400]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 71400])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $order->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 71400,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 71400, 'zefanya_received_at' => now(),
            'confirmed_at' => now(), 'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        $settlement = app(SellerSettlementService::class)->prepare($remittance);
        app(CommissionFinalizationService::class)->finalize($order);
        $this->assertSame(58410, app(SellerPayoutRequestService::class)->availableBalance($seller));

        return [$seller, $admin, $settlement->fresh(), [
            'order' => $order->fresh()->getRawOriginal(), 'payment' => $payment->fresh()->getRawOriginal(),
            'remittance' => $remittance->fresh()->getRawOriginal(),
        ]];
    }

    private function payout(Seller $seller, string $key): array
    {
        return ['action' => 'payout_request', 'seller' => $seller->id, 'amount' => '100.00', 'key' => $key];
    }

    private function settlement(SellerSettlement $settlement, Admin $admin, string $reference = 'MYSQL-SETTLEMENT-A'): array
    {
        return ['action' => 'settlement', 'settlement' => $settlement->id, 'admin' => $admin->id,
            'amount_minor' => 58410, 'reference' => $reference, 'reason' => 'MySQL direct settlement race check.'];
    }

    private function pay(SellerPayoutRequest $payout, Admin $admin, string $reference = 'MYSQL-PAYOUT-A'): array
    {
        return ['action' => 'payout_pay', 'payout' => $payout->id, 'admin' => $admin->id, 'reference' => $reference];
    }

    private function refund(SellerSettlement $settlement, Admin $admin, string $amount, array $attributes = []): array
    {
        $return = ParcelReturn::query()->forceCreate($attributes + [
            'seller_order_id' => $settlement->seller_order_id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged',
            'requested_at' => now(),
            'financial_review_required' => true,
        ]);

        return ['action' => 'payout_refund', 'return' => $return->id, 'admin' => $admin->id, 'amount' => $amount];
    }

    private function assertOverlap(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
            $this->assertArrayNotHasKey('exception', $result);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }

    private function assertCleanOverlap(array $results, SellerSettlement $settlement, array $snapshots): void
    {
        $this->assertOverlap($results);
        $this->assertSame($snapshots['order'], $settlement->sellerOrder->fresh()->getRawOriginal());
        $this->assertSame($snapshots['payment'], $settlement->payment->fresh()->getRawOriginal());
        $this->assertSame($snapshots['remittance'], $settlement->codRemittance->fresh()->getRawOriginal());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_duplicate_payout_requests_reserve_once_with_the_same_or_different_submission_keys(bool $sameKey): void
    {
        [$seller, , $settlement, $snapshots] = $this->fixture();
        $key = (string) Str::uuid();
        $results = Race::run(
            array_replace($this->payout($seller, $key), ['amount' => '584.10', 'hold' => 'seller']),
            array_replace($this->payout($seller, $sameKey ? $key : (string) Str::uuid()), ['amount' => '584.10', 'watch' => 'seller']),
        );

        $this->assertSame([201, $sameKey ? 200 : 422], array_column($results, 'status'));
        if ($sameKey) {
            $this->assertSame($results['a']['payout_id'], $results['b']['payout_id']);
        } else {
            $this->assertSame(['amount'], $results['b']['validation_fields']);
        }
        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(58410, SellerPayoutRequest::query()->sole()->amount_minor);
        $this->assertSame(58410, (int) SellerPayoutAllocation::query()->sum('amount_minor'));
        $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_payout_reservation_and_admin_settlement_cannot_spend_the_same_earnings(bool $reservationFirst): void
    {
        [$seller, $admin, $settlement, $snapshots] = $this->fixture();
        $reservation = $this->payout($seller, (string) Str::uuid());
        $payment = $this->settlement($settlement, $admin);
        $results = Race::run(
            ($reservationFirst ? $reservation : $payment) + ['hold' => 'seller'],
            ($reservationFirst ? $payment : $reservation) + ['watch' => 'seller'],
        );

        $this->assertSame([$reservationFirst ? 201 : 200, 422], array_column($results, 'status'));
        $this->assertSame([$reservationFirst ? 'settlement' : 'amount'], $results['b']['validation_fields']);
        $this->assertSame($reservationFirst ? SellerSettlement::PENDING_PAYOUT : SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame($reservationFirst ? null : 58410, $settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_payout_requests', $reservationFirst ? 1 : 0);
        $this->assertDatabaseCount('seller_payout_allocations', $reservationFirst ? 1 : 0);
        $this->assertSame($reservationFirst ? 48410 : 0, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    public function test_two_admins_cannot_settle_the_same_earnings_twice(): void
    {
        [, $admin, $settlement, $snapshots] = $this->fixture();
        $otherAdmin = Admin::factory()->create(['role' => 'super_admin']);
        $results = Race::run(
            $this->settlement($settlement, $admin) + ['hold' => 'seller'],
            $this->settlement($settlement, $otherAdmin, 'MYSQL-SETTLEMENT-B') + ['watch' => 'seller'],
        );

        $this->assertSame([200, 409], array_column($results, 'status'));
        $this->assertSame(['settlement'], $results['b']['validation_fields']);
        $this->assertDatabaseCount('seller_settlements', 1);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame('MYSQL-SETTLEMENT-A', $settlement->fresh()->payout_reference);
        $this->assertSame($admin->id, $settlement->fresh()->paid_by_admin_id);
        $this->assertDatabaseCount('seller_payout_requests', 0);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_direct_payout')->count());
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    public function test_concurrent_high_value_requests_and_confirmations_cannot_overspend_earnings(): void
    {
        [$seller, $admin, $settlement] = $this->fixture();
        $base = 200000000;
        $cash = $base + 6500;
        $settlement->sellerOrder->forceFill(['subtotal_minor' => $base + 5000, 'commission_minor' => 20000000, 'seller_net_minor' => 180000000])->save();
        $settlement->payment->forceFill(['amount_minor' => $cash, 'collected_amount_minor' => $cash])->save();
        $settlement->codRemittance->forceFill(['amount_minor' => $cash, 'received_amount_minor' => $cash])->save();
        $settlement->forceFill(['collected_amount_minor' => $cash, 'commission_minor' => 20000000, 'seller_payout_minor' => 180000000])->save();
        $service = app(SellerPayoutRequestService::class);
        $this->assertSame(180000000, $service->availableBalance($seller));
        $results = Race::run(
            array_replace($this->payout($seller, (string) Str::uuid()), ['amount' => '1000000.00', 'hold' => 'seller']),
            array_replace($this->payout($seller, (string) Str::uuid()), ['amount' => '1000000.00', 'watch' => 'seller']),
        );
        $this->assertSame([201, 422], array_column($results, 'status'));
        $this->assertSame(100000000, $service->reservedMinor($seller));
        $this->assertSame(80000000, $service->availableBalance($seller));
        $this->assertOverlap($results);

        $payout = SellerPayoutRequest::sole();
        $other = Admin::factory()->create(['role' => 'super_admin']);
        $results = Race::run($this->pay($payout, $admin) + ['hold' => 'seller'], $this->pay($payout, $other) + ['watch' => 'seller']);
        $this->assertSame([302, 302], array_column($results, 'status'));
        $this->assertSame(100000000, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());
        $this->assertOverlap($results);

        app(SellerSettlementService::class)->confirmPayout($settlement, $admin, 80000000, 'HIGH-DIRECT-REMAINDER', 'Seller requested the remaining funds.');
        $finance = app(FinanceReportService::class);
        $this->assertSame(180000000, $finance->totals($finance->ledger(FinanceReportService::period(null)))[FinanceReportService::OUT]);
        $this->assertSame(180000000, app(SellerEarningsService::class)->summary($seller)['paidWithdrawalsMinor']);
    }

    public function test_two_admins_record_one_requested_payout_and_one_audit_event(): void
    {
        [$seller, $admin, $settlement, $snapshots] = $this->fixture();
        $otherAdmin = Admin::factory()->create(['role' => 'super_admin']);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '584.10', (string) Str::uuid());
        $results = Race::run(
            $this->pay($payout, $admin) + ['hold' => 'seller'],
            $this->pay($payout, $otherAdmin, 'MYSQL-PAYOUT-B') + ['watch' => 'seller'],
        );

        $this->assertSame([302, 302], array_column($results, 'status'));
        $this->assertSame(['Payout recorded. The seller has been told.', 'This payout was already recorded.'], array_column($results, 'message'));
        $this->assertSame('MYSQL-PAYOUT-A', $payout->fresh()->payout_reference);
        $this->assertSame($admin->id, (int) $payout->fresh()->decided_by_admin_id);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());
        $this->assertSame(1, $seller->notifications()->count());
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    public function test_refund_waits_for_payout_and_records_a_single_debt_against_paid_earnings(): void
    {
        [$seller, $admin, $settlement] = $this->fixture();
        $refund = $this->refund($settlement, $admin, '714.00');
        $payout = app(SellerPayoutRequestService::class)->request($seller, '584.10', (string) Str::uuid());
        $results = Race::run(
            $this->pay($payout, $admin) + ['hold' => 'seller_settlement'],
            $refund + ['watch' => 'seller_settlement'],
        );

        $this->assertSame([302, 302], array_column($results, 'status'));
        $this->assertSame(SellerPayoutRequest::PAID, $payout->fresh()->status);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertNotNull(ParcelReturn::query()->sole()->refunded_at);
        $debt = SellerDebt::query()->sole();
        $this->assertSame($settlement->id, $debt->seller_settlement_id);
        $this->assertSame(64910, $debt->outstandingMinor());
        $this->assertOverlap($results);
    }

    public function test_payout_waits_for_a_refund_to_finish_updating_its_debt_deduction(): void
    {
        [$seller, $admin, $paid] = $this->fixture();
        $service = app(SellerPayoutRequestService::class);
        $service->pay($service->request($seller, '584.10', (string) Str::uuid()), $admin, 'EARLIER-PAYOUT');
        $refund = $this->refund($paid, $admin, '100.00', ['seller_decision' => ParcelReturn::APPROVED]);
        app(SellerSettlementService::class)->releasePendingForReturn($paid->sellerOrder);
        [, , $settlement] = $this->fixture($seller);
        $payout = $service->request($seller, '584.10', (string) Str::uuid());
        $this->assertSame(58410, $payout->debt_deducted_minor);

        $results = Race::run(
            $refund + ['hold' => 'seller_debt_write'],
            $this->pay($payout, $admin) + ['watch' => 'seller_debt'],
        );

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['payout'], $results['b']['validation_fields']);
        $this->assertSame(9000, $service->outstandingDebtMinor($seller));
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());
        $this->assertOverlap($results);
    }

    public function test_two_admins_record_only_one_refund_and_debt_after_payout(): void
    {
        [$seller, $admin, $settlement] = $this->fixture();
        $payouts = app(SellerPayoutRequestService::class);
        $payout = $payouts->request($seller, '584.10', (string) Str::uuid());
        $payouts->pay($payout, $admin, 'EARLIER-PAYOUT');
        $other = Admin::factory()->create(['role' => 'super_admin']);
        $refund = $this->refund($settlement, $admin, '100.00');

        $results = Race::run(
            $refund + ['hold' => 'parcel', 'recipient' => 'WINNING-TEST-ACCOUNT'],
            ['admin' => $other->id, 'watch' => 'parcel', 'recipient' => 'LOSING-TEST-ACCOUNT'] + $refund,
        );

        $this->assertSame([302, 409], array_column($results, 'status'));
        $this->assertSame(['refund'], $results['b']['validation_fields']);
        $this->assertSame(9000, SellerDebt::query()->sole()->outstandingMinor());
        $this->assertSame(1, ParcelReturn::query()->sole()->refundHistory()->count());
        $this->assertSame('WINNING-TEST-ACCOUNT', ParcelReturn::sole()->refund_recipient);
        $event = ParcelReturn::sole()->refundHistory()->sole();
        $this->assertSame('WINNING-TEST-ACCOUNT', $event->metadata['recipient']);
        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(SellerPayoutRequest::PAID, $payout->fresh()->status);
        $this->assertOverlap($results);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_stale_refund_record_or_void_cannot_overwrite_a_concurrent_replacement(bool $staleVoid): void
    {
        [$seller, $admin, $settlement] = $this->fixture();
        $payouts = app(SellerPayoutRequestService::class);
        $payouts->pay($payouts->request($seller, '584.10', (string) Str::uuid()), $admin, 'EARLIER');
        $task = $this->refund($settlement, $admin, '100.00', ['seller_decision' => ParcelReturn::APPROVED]);
        $record = ParcelReturn::sole();
        $returns = app(ParcelReturnService::class);
        $returns->recordRefund($record, $admin, 'ORIGINAL', 10000, 'ORIGINAL-TEST-ACCOUNT', today()->toDateString(), reason: 'Buyer kept the remaining items.');
        $originalRevision = (int) $record->refundHistory()->max('id');
        $returns->voidRefund($record, $admin, 'The original transfer bounced back.', $originalRevision);
        $replacement = array_replace($task, [
            'amount' => '200.00', 'reference' => 'REPLACEMENT', 'recipient' => 'CORRECTED-TEST-ACCOUNT',
            'refund_revision' => (int) $record->refundHistory()->max('id'), 'hold' => 'parcel',
        ]);
        $stale = array_replace($task, [
            'action' => $staleVoid ? 'payout_refund_void' : 'payout_refund',
            'refund_revision' => $staleVoid ? $originalRevision : 0, 'watch' => 'parcel', 'recipient' => 'STALE-TEST-ACCOUNT',
        ]);
        $results = Race::run($replacement, $stale);

        $this->assertSame([302, 409], array_column($results, 'status'));
        $this->assertSame([$staleVoid ? 'void_reason' : 'refund'], $results['b']['validation_fields']);
        $this->assertSame('REPLACEMENT', $record->fresh()->refund_reference);
        $this->assertSame('CORRECTED-TEST-ACCOUNT', $record->fresh()->refund_recipient);
        $this->assertSame('ORIGINAL-TEST-ACCOUNT', $record->refundHistory()->where('event_key', 'refund_recorded')->sole()->metadata['recipient']);
        $this->assertSame('ORIGINAL-TEST-ACCOUNT', $record->refundHistory()->where('event_key', 'refund_voided')->sole()->metadata['recipient']);
        $this->assertSame(20000, $record->fresh()->refund_amount_minor);
        $this->assertSame(3, $record->refundHistory()->count());
        $this->assertSame(18000, SellerDebt::sole()->outstandingMinor());
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_refund_voided')->count());
        $this->assertOverlap($results);
    }

    public function test_direct_payout_cannot_use_the_original_amount_after_a_concurrent_partial_refund(): void
    {
        [$seller, $admin, $settlement] = $this->fixture();
        $results = Race::run(
            $this->refund($settlement, $admin, '100.00') + ['hold' => 'parcel'],
            $this->settlement($settlement, $admin) + ['watch' => 'parcel'],
        );

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['settlement'], $results['b']['validation_fields']);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(49410, $settlement->fresh()->seller_payout_minor);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_debts', 0);
        $this->assertDatabaseMissing('operations_audit_logs', ['action' => 'seller_direct_payout']);
        $this->assertOverlap($results);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_requested_payout_waits_for_new_refund_debt_and_rejects_its_zero_deduction(bool $existingRow): void
    {
        [$seller, $admin, $paid] = $this->fixture();
        app(SellerSettlementService::class)->confirmPayout($paid, $admin, 58410, 'EARLIER-DIRECT');
        $refund = $this->refund($paid, $admin, '100.00');
        if ($existingRow) {
            SellerDebt::query()->forceCreate([
                'seller_id' => $seller->id, 'seller_order_id' => $paid->seller_order_id,
                'seller_settlement_id' => $paid->id, 'amount_minor' => 0, 'reason' => SellerDebt::AFTER_PAYOUT_RETURN,
            ]);
        }
        [, , $settlement] = $this->fixture($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid());
        $this->assertSame(0, $payout->debt_deducted_minor);
        $results = Race::run(
            $refund + ['hold' => $existingRow ? 'seller_debt_write' : 'seller_debt_insert'],
            // A new debt's Seller foreign key holds a shared parent lock until the refund commits.
            $this->pay($payout, $admin) + ['watch' => $existingRow ? 'seller_debt' : 'seller'],
        );

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['payout'], $results['b']['validation_fields']);
        $this->assertSame(9000, SellerDebt::sole()->outstandingMinor());
        $this->assertSame(0, SellerDebt::sole()->settled_minor);
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseMissing('operations_audit_logs', ['action' => 'seller_payout_paid']);
        $this->assertOverlap($results);
    }

    public function test_direct_payout_waits_for_a_concurrent_refund_debt_update(): void
    {
        [$seller, $admin, $paid] = $this->fixture();
        app(SellerSettlementService::class)->confirmPayout($paid, $admin, 58410, 'EARLIER-DIRECT');
        $refund = $this->refund($paid, $admin, '100.00');
        SellerDebt::query()->forceCreate([
            'seller_id' => $seller->id, 'seller_order_id' => $paid->seller_order_id,
            'seller_settlement_id' => $paid->id, 'amount_minor' => 0, 'reason' => SellerDebt::AFTER_PAYOUT_RETURN,
        ]);
        [, , $settlement] = $this->fixture($seller);

        $results = Race::run(
            $refund + ['hold' => 'seller_debt_write'],
            $this->settlement($settlement, $admin) + ['watch' => 'seller_debt'],
        );

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['settlement'], $results['b']['validation_fields']);
        $this->assertSame(9000, SellerDebt::query()->sole()->outstandingMinor());
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseMissing('operations_audit_logs', ['action' => 'seller_direct_payout']);
        $this->assertOverlap($results);
    }
}
