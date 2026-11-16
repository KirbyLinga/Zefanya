<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\PlatformRefundExpense;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Admin\FinanceReportService;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Buyer\BuyerAccountDeletionService;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Audit 1.13 follow-up: Zefanya as the third payer of an exception refund. The refund is a
 * separate platform expense; Seller earnings, commission, settlements, COD remittance and
 * Logistics allocations stay exactly as they were. One active refund per return (partial
 * refunds stay final), as before.
 */
class PlatformFundedRefundTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EXCEPTION = ['refund_exception_basis' => 'separate_approval', 'refund_exception_note' => 'Goodwill refund approved by the finance lead, FIN-77.'];

    private const PLATFORM = ['refund_funding_party' => 'platform', 'refund_funding_note' => 'Zefanya absorbs this goodwill refund per FIN-77.'];

    private SellerOrder $parcel;

    private Seller $seller;

    private Buyer $buyer;

    private Admin $admin;

    private LogisticsProvider $provider;

    private SellerSettlement $settlement;

    private CodRemittance $remittance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:00:00', 'UTC'));

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        // ₱450 item + ₱50 delivery = ₱500 collected; finalized at the current 10%: ₱45 commission, ₱405 Seller net.
        $this->parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id, 'discount_minor' => 0])->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->parcel->forceFill(['subtotal_minor' => 45000, 'shipping_fee_minor' => 5000, 'carrier_provider_id' => $this->provider->id,
            'commission_bps' => 1000, 'commission_minor' => 4500, 'seller_net_minor' => 40500, 'commission_finalized_at' => now()])->save();
        StatusHistory::query()->create(['seller_order_id' => $this->parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web']);
        $payment = Payment::factory()->create(['seller_order_id' => $this->parcel->id, 'amount_minor' => 50000]);
        $payment->recordCodCollection(50000, 'rider', 1);
        $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $this->remittance = (new CodRemittance)->forceFill(['payment_id' => $payment->id, 'seller_order_id' => $this->parcel->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $this->provider->id, 'amount_minor' => 50000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'confirmed_by_id' => $this->provider->id, 'confirmed_at' => now(),
            'received_amount_minor' => 50000, 'zefanya_received_at' => now()]);
        $this->remittance->snapshotAllocation();
        $this->remittance->save();
        $this->settlement = (new SellerSettlement)->forceFill(['seller_order_id' => $this->parcel->id, 'cod_remittance_id' => $this->remittance->id,
            'payment_id' => $payment->id, 'seller_id' => $this->seller->id, 'collected_amount_minor' => 50000,
            'shipping_allocation_minor' => 5000, 'commission_bps' => 1000, 'commission_minor' => 4500, 'seller_payout_minor' => 40500,
            'status' => SellerSettlement::PAID, 'paid_amount_minor' => 40500, 'financials_finalized_at' => now()]);
        $this->settlement->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rejected(): ParcelReturn
    {
        app(BuyerShipmentConfirmation::class)->confirm($this->parcel->order, $this->buyer, $this->parcel->id);
        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.requestReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'Item arrived damaged', 'description' => 'The delivered item arrived damaged.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $record = ParcelReturn::sole();
        app(ParcelReturnService::class)->rejectBySeller($record, $this->seller, 'The item was fine when shipped.');

        return $record->fresh();
    }

    /** @param array<string, string> $extra */
    private function recordRefund(ParcelReturn $record, array $extra = [], ?Admin $admin = null): TestResponse
    {
        return $this->actingAs($admin ?? $this->admin, 'admin')->postJson(route('admin.returns.refund', $record), $extra + [
            'reference' => 'GCASH-5001', 'amount' => '500.00', 'recipient' => 'Juana Cruz 0917', 'paid_on' => now('Asia/Manila')->toDateString(),
            'refund_revision' => (string) (int) $record->refundHistory()->max('id'),
        ]);
    }

    private function voidRefund(ParcelReturn $record, ?int $revision = null): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.void', $record), [
            'void_reason' => 'The GCash transfer bounced back.', 'refund_revision' => $revision ?? (int) $record->refundHistory()->max('id'),
        ]);
    }

    /** Every untouchable money figure: commission, Seller net, settlement, remittance and its 40/60 split, shipping. */
    private function untouchable(): array
    {
        return [
            'order' => $this->parcel->fresh()->only(['commission_bps', 'commission_minor', 'seller_net_minor', 'shipping_fee_minor', 'subtotal_minor']),
            'finalized' => $this->parcel->fresh()->commission_finalized_at?->toIso8601String(),
            'settlement' => $this->settlement->fresh()->only(['status', 'commission_bps', 'commission_minor', 'seller_payout_minor', 'paid_amount_minor', 'shipping_allocation_minor', 'collected_amount_minor', 'pending_reason']),
            'remittance' => collect($this->remittance->fresh()->getAttributes())->except(['updated_at'])->all(),
            'payment' => $this->parcel->payment->fresh()->only(['status', 'amount_minor', 'collected_amount_minor']),
        ];
    }

    private function noSellerOrLogisticsLiability(): void
    {
        $this->assertSame(0, SellerDebt::query()->count());
        $this->assertSame(0, LogisticsReturnCharge::query()->count());
    }

    public function test_an_admin_without_super_admin_role_cannot_record_a_platform_funded_refund(): void
    {
        $record = $this->rejected();
        $before = $this->untouchable();

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM, Admin::factory()->create(['role' => 'admin']))->assertForbidden();

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
        $this->assertSame($before, $this->untouchable());
    }

    #[TestWith(['super_admin'])]
    public function test_super_admin_records_a_platform_funded_exception_refund(string $role): void
    {
        $record = $this->rejected();
        $before = $this->untouchable();
        $admin = Admin::factory()->create(['role' => $role]);

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM, $admin)->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame([ParcelReturn::FAULT_PLATFORM, (int) $admin->id], [$record->fault_party, (int) $record->fault_set_by_admin_id]);
        $this->assertSame(self::PLATFORM['refund_funding_note'], $record->fault_note);
        $this->assertNull($record->return_shipping_fee_minor);
        $this->noSellerOrLogisticsLiability();
        $this->assertSame($before, $this->untouchable(), 'Commission, Seller net, settlement, remittance and shipping are unchanged.');

        $event = $record->refundHistory()->sole();
        $this->assertSame('platform', $event->metadata['exception']['funding_party']);
        $expense = PlatformRefundExpense::query()->sole();
        $this->assertSame([PlatformRefundExpense::EXPENSE, 50000, 'PRF-'.$event->id], [$expense->entry_type, $expense->amount_minor, $expense->reference]);
        $this->assertSame([(int) $record->id, (int) $this->parcel->id, (int) $this->parcel->payment->id, (int) $event->id, (int) $admin->id],
            [(int) $expense->parcel_return_id, (int) $expense->seller_order_id, (int) $expense->payment_id, (int) $expense->refund_event_id, (int) $expense->admin_id]);
        $this->assertSame(self::PLATFORM['refund_funding_note'], $expense->justification);
        $this->assertNull($expense->reverses_id);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'platform_refund_expense_recorded')->count());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_fault_set')->count());
    }

    public function test_moderator_cannot_record_a_platform_funded_refund(): void
    {
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM, Admin::factory()->create(['role' => 'moderator']))->assertForbidden();

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertNull($record->fresh()->fault_party);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
    }

    public function test_missing_justification_or_invalid_payer_is_rejected_without_writes(): void
    {
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'platform'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_funding_note');
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'platform', 'refund_funding_note' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_funding_note');
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'zefanya', 'refund_funding_note' => 'Zefanya absorbs this goodwill refund.'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_funding_party');

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertNull($record->fresh()->fault_party);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
        $this->assertSame(0, OperationsAuditLog::query()->count());
    }

    public function test_an_earlier_seller_or_logistics_decision_conflicts_with_platform(): void
    {
        app(BuyerShipmentConfirmation::class)->confirm($this->parcel->order, $this->buyer, $this->parcel->id);
        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.requestReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'Item arrived damaged', 'description' => 'The delivered item arrived damaged.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $record = ParcelReturn::sole();
        $this->actingAs($this->admin, 'admin')->post(route('admin.returns.fault', $record), [
            'fault_party' => ParcelReturn::FAULT_LOGISTICS, 'fault_note' => 'Courier crushed the box in transit.',
        ])->assertSessionHasNoErrors();

        $this->recordRefund($record->fresh(), self::EXCEPTION + self::PLATFORM)->assertConflict()->assertJsonValidationErrors('refund_funding_party');

        $this->assertSame(ParcelReturn::FAULT_LOGISTICS, $record->fresh()->fault_party);
        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
    }

    public function test_a_platform_decision_cannot_be_replaced_by_who_pays_or_a_later_refund(): void
    {
        $record = $this->rejected();
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect();
        $this->voidRefund($record->fresh())->assertRedirect();

        $this->assertFalse($record->fresh()->faultCanChange());
        $this->recordRefund($record->fresh(), self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'Moving this to the Seller instead.'])
            ->assertConflict()->assertJsonValidationErrors('refund_funding_party');
        $this->assertSame(ParcelReturn::FAULT_PLATFORM, $record->fresh()->fault_party);
        $this->noSellerOrLogisticsLiability();
    }

    public function test_refund_above_collected_cod_is_rejected(): void
    {
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM + ['amount' => '500.01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM + ['amount' => '0.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM + ['amount' => '-5.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(0, PlatformRefundExpense::query()->count());
        $this->assertNull($record->fresh()->fault_party);
    }

    public function test_partial_refund_books_the_exact_amount_and_stays_final(): void
    {
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM + ['amount' => '123.45', 'refund_reason' => 'Only the strap was faulty and it was replaced.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12345, PlatformRefundExpense::query()->sole()->amount_minor);
        $this->assertSame(12345, $record->fresh()->refund_amount_minor);
        // Existing rule kept: a recorded partial refund is the final decision, nothing stays outstanding.
        $this->assertSame([0, 0], array_values(array_intersect_key(app(PlatformFinanceService::class)->position(), array_flip(['refundsToRecordCount', 'refundsToRecordMinor']))));
        $this->noSellerOrLogisticsLiability();
    }

    public function test_partial_then_void_then_replacement_reconciles_to_the_centavo(): void
    {
        $record = $this->rejected();
        $before = $this->untouchable();
        $outstanding = fn (): array => array_values(array_intersect_key(app(PlatformFinanceService::class)->position(), array_flip(['refundsToRecordCount', 'refundsToRecordMinor'])));
        $deletion = fn (): ?string => app(BuyerAccountDeletionService::class)->blockingReason($this->buyer->fresh());
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        $before['order'] = $this->parcel->fresh()->only(['commission_bps', 'commission_minor', 'seller_net_minor', 'shipping_fee_minor', 'subtotal_minor']);

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM + ['amount' => '200.00', 'refund_reason' => 'Partial goodwill refund agreed with the buyer.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([0, 0], $outstanding());
        $this->assertNull($deletion());

        $this->voidRefund($record->fresh())->assertRedirect();
        $this->assertSame([1, 50000], $outstanding(), 'A voided authorized exception is owed again (up to collected COD).');
        $this->assertNotNull($deletion());
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()->assertSee('Your refund transfer did not go through.');

        // Duplicate or stale void: rejected, still exactly one reversal.
        $this->voidRefund($record->fresh(), 0)->assertConflict();
        $this->assertSame(1, PlatformRefundExpense::query()->where('entry_type', PlatformRefundExpense::REVERSAL)->count());

        // The voided transfer's reference stays protected: a ₱300 replacement is a new transfer.
        $this->recordRefund($record->fresh(), self::EXCEPTION + ['amount' => '300.00', 'refund_reason' => 'Revised goodwill refund agreed with the buyer.'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertNull($record->fresh()->refunded_at);

        // Replacement: the established Platform payer stands without a new decision.
        $this->recordRefund($record->fresh(), self::EXCEPTION + ['reference' => 'GCASH-5002', 'amount' => '300.00', 'refund_reason' => 'Revised goodwill refund agreed with the buyer.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([0, 0], $outstanding());
        $this->assertNull($deletion());
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))->assertOk()->assertSee('Refund recorded.');

        $rows = PlatformRefundExpense::query()->orderBy('id')->get();
        $this->assertSame([['expense', 20000, null], ['reversal', 20000, $rows[0]->id], ['expense', 30000, null]],
            $rows->map(fn ($row) => [$row->entry_type, $row->amount_minor, $row->reverses_id])->all());
        $this->assertSame($rows[0]->reference.'-R', $rows[1]->reference);
        $this->assertSame(['refund_recorded', 'refund_voided', 'refund_recorded_2'], $record->refundHistory()->pluck('event_key')->all());
        $this->assertSame([(int) $rows[0]->refund_event_id, (int) $rows[2]->refund_event_id],
            $record->refundHistory()->where('event_key', 'like', 'refund_recorded%')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $net = $rows->sum(fn ($row) => $row->entry_type === 'expense' ? $row->amount_minor : -$row->amount_minor);
        $this->assertSame(30000, $net);
        $this->assertSame((int) $record->fresh()->refund_amount_minor, $net, 'Net platform expense equals the active refund.');
        $this->assertSame(30000, app(FinanceReportService::class)->summary(FinanceReportService::period(now('Asia/Manila')->format('Y-m')))['platformRefundExpenseMinor']);
        $this->noSellerOrLogisticsLiability();
        $this->assertSame($before, $this->untouchable());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'platform_refund_expense_reversed')->count());
    }

    public function test_historical_five_percent_commission_snapshot_is_untouched(): void
    {
        $this->parcel->forceFill(['commission_bps' => 500, 'commission_minor' => 2250, 'seller_net_minor' => 42750, 'status' => OrderStatus::Delivered])->save();
        $this->settlement->forceFill(['commission_bps' => 500, 'commission_minor' => 2250, 'seller_payout_minor' => 42750, 'paid_amount_minor' => 42750])->save();
        $record = $this->rejected();
        $before = $this->untouchable();

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect()->assertSessionHasNoErrors();
        $this->voidRefund($record->fresh())->assertRedirect();

        $this->assertSame($before, $this->untouchable());
        $this->assertSame(500, $this->parcel->fresh()->commission_bps);
    }

    public function test_finance_reports_the_expense_separately_by_manila_month(): void
    {
        $record = $this->rejected();
        // 2026-11-30 16:30 UTC is already 2026-12-01 00:30 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-30 16:30:00', 'UTC'));
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect()->assertSessionHasNoErrors();
        Carbon::setTestNow(Carbon::parse('2026-12-20 02:00:00', 'UTC'));

        $reports = app(FinanceReportService::class);
        $december = $reports->summary(Carbon::createFromFormat('!Y-m', '2026-12', 'Asia/Manila'));
        $november = $reports->summary(Carbon::createFromFormat('!Y-m', '2026-11', 'Asia/Manila'));
        $this->assertSame(50000, $december['platformRefundExpenseMinor']);
        $this->assertSame(0, $november['platformRefundExpenseMinor']);
        // Shown on its own line; commission and net platform earnings are not reduced by it.
        $this->assertSame($november['commissionMinor'] - $november['platformVoucherMinor'] - $november['shippingVoucherMinor'], $november['netMinor']);
        $this->assertSame(4500, $november['commissionMinor']);
        $this->actingAs($this->admin, 'admin')->get(route('admin.finance.index', ['month' => '2026-12']))
            ->assertOk()->assertSee('Platform refund expense')->assertSee('−₱500.00');
    }

    public function test_seller_page_says_the_seller_is_not_charged(): void
    {
        $record = $this->rejected();
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect();

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()->assertSee('this exception refund is paid by the platform');
    }

    public function test_admin_form_offers_platform_only_for_a_new_exception_funding_decision(): void
    {
        $record = $this->rejected();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertSee('value="platform"', false);

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect();
        $this->voidRefund($record->fresh())->assertRedirect();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertDontSee('name="refund_funding_party"', false)->assertSee('Zefanya (platform-funded exception refund)');
    }

    #[TestWith(['expense'])]
    #[TestWith(['audit'])]
    public function test_a_failure_while_booking_the_expense_rolls_back_every_write(string $failAt): void
    {
        $record = $this->rejected();
        $before = [$this->untouchable(), $record->fresh()->only(['refunded_at', 'fault_party', 'fault_note', 'fault_set_by_admin_id', 'refund_amount_minor']),
            $record->events()->count(), OperationsAuditLog::query()->count()];
        if ($failAt === 'expense') {
            PlatformRefundExpense::creating(fn () => throw new \RuntimeException('Simulated expense write failure.'));
        } else {
            OperationsAuditLog::creating(function (OperationsAuditLog $log): void {
                if ($log->action === 'platform_refund_expense_recorded') {
                    throw new \RuntimeException('Simulated audit write failure.');
                }
            });
        }

        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertServerError();

        $this->assertSame($before, [$this->untouchable(), $record->fresh()->only(['refunded_at', 'fault_party', 'fault_note', 'fault_set_by_admin_id', 'refund_amount_minor']),
            $record->events()->count(), OperationsAuditLog::query()->count()]);
        $this->assertSame(0, PlatformRefundExpense::query()->count());
    }

    public function test_a_failure_while_reversing_rolls_back_the_void(): void
    {
        $record = $this->rejected();
        $this->recordRefund($record, self::EXCEPTION + self::PLATFORM)->assertRedirect();
        PlatformRefundExpense::creating(fn () => throw new \RuntimeException('Simulated reversal write failure.'));

        $this->voidRefund($record->fresh())->assertServerError();

        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertSame(['refund_recorded'], $record->refundHistory()->pluck('event_key')->all());
        $this->assertSame([PlatformRefundExpense::EXPENSE], PlatformRefundExpense::query()->pluck('entry_type')->all());
    }
}
