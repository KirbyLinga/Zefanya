<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
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
use App\Models\Shared\StatusHistory;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\ParcelReturnService;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Audit 1.13: return decisions and refunds racing on independent MySQL connections. */
class ReturnDecisionConcurrencyTest extends TestCase
{
    use UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    private function requested(bool $legacy = false): array
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id])->id, 'seller_id' => $seller->id,
        ]);
        StatusHistory::query()->create(['seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web']);
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);
        $payment->recordCodCollection(10000, 'rider', 1);
        if ($legacy) {
            $return = new ParcelReturn;
            $return->forceFill([
                'seller_order_id' => $parcel->id, 'status' => ParcelReturnStatus::Requested,
                'reason' => 'Item arrived damaged', 'requested_at' => now(),
                'seller_response_due_at' => now()->addDays(ParcelReturn::SELLER_DECISION_DAYS),
            ])->save();
        } else {
            app(BuyerShipmentConfirmation::class)->confirm($parcel->order, $buyer, $parcel->id);
            $return = app(ParcelReturnService::class)->requestByBuyer($parcel, $buyer, 'Item arrived damaged', 'The delivered item arrived damaged.');
        }

        return compact('buyer', 'seller', 'parcel', 'return');
    }

    private function rejected(): array
    {
        $f = $this->requested(legacy: true);
        app(ParcelReturnService::class)->rejectBySeller($f['return'], $f['seller'], 'The item was fine when shipped.');
        $this->assertSame(ParcelReturnStatus::Rejected, $f['return']->fresh()->status);

        return $f;
    }

    private function noSqlErrors(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors'], 'Unexpected MySQL errors: '.json_encode($result));
        }
    }

    public function test_completion_winning_the_parcel_lock_rejects_a_concurrent_escalation(): void
    {
        $f = $this->rejected();
        $results = Race::run(
            ['action' => 'confirm_received', 'buyer' => $f['buyer']->id, 'order' => $f['parcel']->order_id, 'hold' => 'parcel'],
            ['action' => 'return_escalate', 'buyer' => $f['buyer']->id, 'return' => $f['return']->id, 'watch' => 'parcel'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertSame(409, $results['b']['status']);
        $this->assertSame(OrderStatus::Completed, $f['parcel']->fresh()->status);
        $this->assertNotNull($f['parcel']->fresh()->commission_finalized_at);
        $this->assertSame(ParcelReturnStatus::Rejected, $f['return']->fresh()->status);
        $this->assertNull($f['return']->fresh()->escalated_at);
        $this->assertFalse($f['return']->events()->where('event_key', 'buyer_escalated')->exists());
    }

    public function test_escalation_winning_the_parcel_lock_blocks_a_concurrent_completion(): void
    {
        $f = $this->rejected();
        $results = Race::run(
            ['action' => 'return_escalate', 'buyer' => $f['buyer']->id, 'return' => $f['return']->id, 'hold' => 'parcel'],
            ['action' => 'confirm_received', 'buyer' => $f['buyer']->id, 'order' => $f['parcel']->order_id, 'watch' => 'parcel'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
        $this->assertNull($f['parcel']->fresh()->commission_finalized_at);
        $this->assertSame(ParcelReturnStatus::InDispute, $f['return']->fresh()->status);
        $this->assertSame(1, $f['return']->events()->where('event_key', 'buyer_escalated')->count());
    }

    #[TestWith(['return_approve', 'return_reject'])]
    #[TestWith(['return_reject', 'return_approve'])]
    public function test_conflicting_seller_decisions_commit_only_the_first(string $first, string $second): void
    {
        $f = $this->requested();
        $task = ['seller' => $f['seller']->id, 'return' => $f['return']->id];
        $results = Race::run(['action' => $first] + $task + ['hold' => 'parcel'], ['action' => $second] + $task + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertSame(409, $results['b']['status']);
        $return = $f['return']->fresh();
        $approved = $first === 'return_approve';
        $this->assertSame($approved ? ParcelReturn::APPROVED : ParcelReturn::REJECTED, $return->seller_decision);
        $this->assertSame($approved ? ParcelReturnStatus::Requested : ParcelReturnStatus::Rejected, $return->status);
        $this->assertSame($approved ? 1 : 0, $return->events()->where('event_key', 'seller_approved')->count());
        $this->assertSame($approved ? 0 : 1, $return->events()->where('event_key', 'seller_rejected')->count());
        $this->assertSame($approved ? null : 'The item was fine when shipped.', $return->seller_decision_reason);
    }

    /** An approved buyer return whose collected cash is still with Logistics. */
    private function refundable(): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $rider = Rider::factory()->create(['logistics_provider_id' => $provider->id, 'status' => Rider::STATUS_APPROVED]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id, 'rider_id' => $rider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['delivery_courier_id' => $courier->id, 'subtotal_minor' => 8000]);
        $order->forceFill(['shipping_fee_minor' => 500])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 8500]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 8500])->save();
        $remittance = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 8500, 'status' => CodRemittance::PENDING_REMITTANCE]);
        $remittance->snapshotAllocation();
        $remittance->save();
        $return = (new ParcelReturn)->forceFill(['seller_order_id' => $order->id, 'status' => ParcelReturnStatus::Requested,
            'reason' => 'Item arrived damaged', 'requested_at' => now()->subDays(2), 'seller_response_due_at' => now()->subDay(),
            'seller_decision' => ParcelReturn::APPROVED, 'seller_decided_at' => now()->subDay(), 'fault_party' => ParcelReturn::FAULT_SELLER,
            'return_shipping_fee_minor' => 500, 'financial_review_required' => true, 'delivery_attempts' => 0]);
        $return->save();
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        return compact('provider', 'rider', 'order', 'payment', 'remittance', 'return', 'admin');
    }

    /** A pending buyer return (not approved, so a refund needs a documented exception). */
    private function pendingRefundable(): array
    {
        $f = $this->refundable();
        $f['return']->forceFill(['seller_decision' => null, 'seller_decided_at' => null, 'fault_party' => null,
            'return_shipping_fee_minor' => null, 'seller_response_due_at' => now()->addDays(2)])->save();

        return $f;
    }

    private function exceptionRefund(array $f, string $party): array
    {
        return ['action' => 'return_refund', 'admin' => $f['admin']->id, 'return' => $f['return']->id, 'amount' => '85.00',
            'extra' => ['refund_exception_basis' => 'legal_requirement', 'refund_exception_note' => 'Consumer Act refund ordered by DTI case 2026-114.',
                'refund_funding_party' => $party, 'refund_funding_note' => 'Decided by the finance lead in ticket FIN-9.']];
    }

    public function test_payer_decision_winning_the_lock_refuses_a_stale_conflicting_exception_refund(): void
    {
        $f = $this->pendingRefundable();
        $results = Race::run(
            ['action' => 'return_fault', 'admin' => $f['admin']->id, 'return' => $f['return']->id, 'party' => ParcelReturn::FAULT_LOGISTICS, 'hold' => 'parcel'],
            $this->exceptionRefund($f, ParcelReturn::FAULT_SELLER) + ['watch' => 'parcel'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertSame(409, $results['b']['status']);
        $this->assertSame(['refund_funding_party'], $results['b']['validation_fields']);
        $return = $f['return']->fresh();
        $this->assertNull($return->refunded_at);
        $this->assertSame(ParcelReturn::FAULT_LOGISTICS, $return->fault_party);
        $this->assertSame(0, $return->refundHistory()->count());
        $this->assertSame(0, SellerDebt::query()->count());
    }

    public function test_exception_refund_winning_the_lock_fixes_its_payer_against_a_later_decision(): void
    {
        $f = $this->pendingRefundable();
        $results = Race::run(
            $this->exceptionRefund($f, ParcelReturn::FAULT_SELLER) + ['hold' => 'parcel'],
            ['action' => 'return_fault', 'admin' => $f['admin']->id, 'return' => $f['return']->id, 'party' => ParcelReturn::FAULT_LOGISTICS, 'watch' => 'parcel'],
        );

        $this->noSqlErrors($results);
        $this->assertSame(302, $results['a']['status'], json_encode($results['a']));
        $this->assertSame(409, $results['b']['status']);
        $return = $f['return']->fresh();
        $this->assertSame(8500, $return->refund_amount_minor);
        $this->assertSame([ParcelReturn::FAULT_SELLER, (int) $f['admin']->id], [$return->fault_party, (int) $return->fault_set_by_admin_id]);
        $this->assertNull($return->return_shipping_fee_minor);
        $this->assertSame('seller', $return->refundHistory()->sole()->metadata['exception']['funding_party']);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_fault_set')->count());
    }

    public function test_unpaid_refund_reporting_counts_only_authorized_obligations_on_mysql(): void
    {
        $rejected = $this->pendingRefundable();
        $rejected['return']->forceFill(['status' => ParcelReturnStatus::Rejected, 'seller_decision' => ParcelReturn::REJECTED, 'seller_decided_at' => now()])->save();
        $approved = $this->refundable();
        $voided = $this->pendingRefundable();
        $service = app(ParcelReturnService::class);
        $service->recordRefund($voided['return'], $voided['admin'], 'GCASH-1', 8500, 'Buyer GCash', today()->toDateString(), null, null, 0,
            'separate_approval', 'Approved by the finance lead in ticket FIN-9.', ParcelReturn::FAULT_SELLER, 'The Seller accepted liability in ticket FIN-9.');
        $service->voidRefund($voided['return']->fresh(), $voided['admin'], 'The GCash transfer bounced back.', (int) $voided['return']->refundHistory()->max('id'));

        // The JSON exception marker is evaluated by InnoDB, not only SQLite.
        $owed = ParcelReturn::query()->awaitingRefundRecording()->orderBy('id')->pluck('id')->all();
        $this->assertSame([(int) $approved['return']->id, (int) $voided['return']->id], array_map('intval', $owed));
        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame([2, 17000], [$position['refundsToRecordCount'], $position['refundsToRecordMinor']]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_refund_and_cod_handover_converge_in_either_lock_order(bool $refundFirst): void
    {
        $f = $this->refundable();
        $refund = ['action' => 'return_refund', 'admin' => $f['admin']->id, 'return' => $f['return']->id, 'amount' => '85.00'];
        $handover = ['action' => 'cod_handover', 'provider' => $f['provider']->id, 'remittance' => $f['remittance']->id,
            'data' => ['received_amount' => '85.00']];
        $results = $refundFirst
            ? Race::run($refund + ['hold' => 'parcel'], $handover + ['watch' => 'parcel'])
            : Race::run($handover + ['hold' => 'parcel'], $refund + ['watch' => 'parcel']);

        $this->noSqlErrors($results);
        $this->assertSame([302, 302], [$results['a']['status'], $results['b']['status']], json_encode($results));

        $return = $f['return']->fresh();
        $this->assertSame(8500, $return->refund_amount_minor);
        $this->assertSame(1, $return->refundHistory()->count());
        $payment = $f['payment']->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(8500, $payment->collected_amount_minor);
        $remittance = $f['remittance']->fresh();
        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $remittance->status);
        $this->assertSame(8500, $remittance->received_amount_minor);

        // Full refund: item amount refunded first, refunded shipping and the return trip become one Seller debt.
        $settlement = SellerSettlement::query()->sole();
        $this->assertSame(SellerSettlement::RELEASED, $settlement->status);
        $this->assertSame(0, (int) $settlement->seller_payout_minor);
        $debt = SellerDebt::query()->sole();
        $this->assertSame(1000, (int) $debt->amount_minor);
        $this->assertSame(0, (int) $debt->settled_minor);
        $this->assertSame((int) $f['order']->id, (int) $debt->seller_order_id);
    }
}
