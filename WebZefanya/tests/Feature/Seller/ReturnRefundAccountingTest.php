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
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
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
 * Audit 1.13 accounting corrections:
 * 1. Unpaid refunds are established, authorized obligations only (approved returns, or an
 *    authorized exception refund later voided), counted once.
 * 2. An exception refund never defaults the Seller as payer; without an earlier Admin
 *    decision it needs an explicit, justified payer, and charges no return trip.
 */
class ReturnRefundAccountingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EXCEPTION = ['refund_exception_basis' => 'separate_approval', 'refund_exception_note' => 'Approved by the finance lead in ticket FIN-9.'];

    private SellerOrder $parcel;

    private Seller $seller;

    private Buyer $buyer;

    private Admin $admin;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        // ₱100 item + ₱5 delivery; the buyer paid ₱105 at the door.
        $this->parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id, 'discount_minor' => 0])->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->parcel->forceFill(['subtotal_minor' => 10000, 'shipping_fee_minor' => 500, 'carrier_provider_id' => $this->provider->id,
            'commission_bps' => 1000, 'commission_minor' => 1000, 'seller_net_minor' => 9000, 'commission_finalized_at' => now()])->save();
        StatusHistory::query()->create([
            'seller_order_id' => $this->parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        Payment::factory()->create(['seller_order_id' => $this->parcel->id, 'amount_minor' => 10500])
            ->recordCodCollection(10500, 'rider', 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The Seller was already paid ₱90 for this order (completed payout). */
    private function paidSettlement(): SellerSettlement
    {
        $payment = $this->parcel->payment;
        $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $remittance = (new CodRemittance)->forceFill(['payment_id' => $payment->id, 'seller_order_id' => $this->parcel->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $this->provider->id, 'amount_minor' => 10500,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'confirmed_by_id' => $this->provider->id, 'confirmed_at' => now(),
            'received_amount_minor' => 10500, 'zefanya_received_at' => now()]);
        $remittance->save();
        $settlement = (new SellerSettlement)->forceFill(['seller_order_id' => $this->parcel->id, 'cod_remittance_id' => $remittance->id,
            'payment_id' => $payment->id, 'seller_id' => $this->seller->id, 'collected_amount_minor' => 10500,
            'shipping_allocation_minor' => 500, 'status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000]);
        $settlement->save();

        return $settlement;
    }

    private function requested(): ParcelReturn
    {
        app(BuyerShipmentConfirmation::class)->confirm($this->parcel->order, $this->buyer, $this->parcel->id);
        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.requestReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'Item arrived damaged', 'description' => 'The delivered item arrived damaged.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return ParcelReturn::sole();
    }

    private function rejected(): ParcelReturn
    {
        $record = $this->requested();
        app(ParcelReturnService::class)->rejectBySeller($record, $this->seller, 'The item was fine when shipped.');

        return $record->fresh();
    }

    private function upheld(): ParcelReturn
    {
        $record = $this->rejected();
        app(ParcelReturnService::class)->escalateByBuyer($record, $this->buyer, 'The photos show the damage the seller denied.');
        app(ParcelReturnService::class)->resolveDispute($record->fresh(), $this->admin, ParcelReturn::DISPUTE_UPHELD, 'The photos do not show the claimed damage.');

        return $record->fresh();
    }

    private function approved(): ParcelReturn
    {
        $record = $this->requested();
        app(ParcelReturnService::class)->approveBySeller($record, $this->seller);

        return $record->fresh();
    }

    /** @param array<string, string> $extra */
    private function recordRefund(ParcelReturn $record, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund', $record), $extra + [
            'reference' => 'GCASH-1001', 'amount' => '105.00', 'recipient' => 'Juana Cruz 0917', 'paid_on' => now('Asia/Manila')->toDateString(),
            'refund_revision' => (string) (int) $record->refundHistory()->max('id'),
        ]);
    }

    private function voidRefund(ParcelReturn $record): void
    {
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.void', $record), [
            'void_reason' => 'The GCash transfer bounced back.', 'refund_revision' => (int) $record->refundHistory()->max('id'),
        ])->assertRedirect();
    }

    /** @return array{0: int, 1: int} */
    private function unpaid(): array
    {
        $position = app(PlatformFinanceService::class)->position();

        return [$position['refundsToRecordCount'], $position['refundsToRecordMinor']];
    }

    // ── 1. Unpaid refund reporting ───────────────────────────────────────

    #[TestWith(['pending'])]
    #[TestWith(['rejected'])]
    #[TestWith(['upheld'])]
    public function test_a_request_that_was_not_approved_is_not_an_unpaid_refund(string $state): void
    {
        $record = match ($state) {
            'pending' => $this->requested(),
            'rejected' => $this->rejected(),
            'upheld' => $this->upheld(),
        };

        $this->assertTrue($record->financial_review_required);
        $this->assertSame([0, 0], $this->unpaid());
        // The same scope drives the Admin dashboard "Refunds to record" count.
        $this->assertFalse(ParcelReturn::query()->awaitingRefundRecording()->exists());
    }

    public function test_an_approved_return_is_one_unpaid_refund_at_the_collected_amount(): void
    {
        $this->approved();

        $this->assertSame([1, 10500], $this->unpaid());
    }

    public function test_exception_refund_counts_only_while_its_voided_transfer_is_still_owed(): void
    {
        $this->paidSettlement();
        $record = $this->rejected();
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'The Seller accepted liability in ticket FIN-9.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([0, 0], $this->unpaid(), 'A recorded exception refund is paid, not owed.');

        $this->voidRefund($record->fresh());
        $this->assertTrue($record->fresh()->refundObligationEstablished());
        $this->assertSame([1, 10500], $this->unpaid(), 'The authorized exception is still owed after a failed transfer.');

        // A replacement is recorded once; history keeps both attempts and the void.
        $this->recordRefund($record->fresh(), self::EXCEPTION)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([0, 0], $this->unpaid());
        $this->assertSame(['refund_recorded', 'refund_voided', 'refund_recorded_2'], $record->refundHistory()->pluck('event_key')->all());
        // One order debt follows the current refund; the replacement adds no second liability.
        $this->assertSame(9500, (int) SellerDebt::query()->sole()->amount_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_fault_set')->count());
    }

    public function test_a_partial_refund_is_not_also_counted_as_unpaid(): void
    {
        $record = $this->approved();
        $this->recordRefund($record, ['amount' => '40.00', 'refund_reason' => 'Only the strap was faulty and it was replaced.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([0, 0], $this->unpaid());
        $this->assertSame(4000, (int) ParcelReturn::query()->where('refunded_at', '>=', now()->startOfMonth())->sum('refund_amount_minor'));
    }

    public function test_a_rejected_return_does_not_block_account_deletion_or_tell_the_buyer_a_refund_is_coming(): void
    {
        $record = $this->rejected();
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()->assertDontSee('Your refund is being reviewed');
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();

        $this->assertNull(app(BuyerAccountDeletionService::class)->blockingReason($this->buyer->fresh()));
        $this->assertTrue($record->fresh()->financial_review_required);
    }

    public function test_an_owed_refund_still_blocks_account_deletion(): void
    {
        $record = $this->rejected();
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'The Seller accepted liability in ticket FIN-9.'])
            ->assertRedirect();
        $this->voidRefund($record->fresh());
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();

        $this->assertSame('You have a refund being processed. You can delete your account after you receive it.',
            app(BuyerAccountDeletionService::class)->blockingReason($this->buyer->fresh()));
    }

    // ── 2. Exception refund funding ──────────────────────────────────────

    public function test_an_exception_refund_without_a_payer_decision_creates_no_liability(): void
    {
        $settlement = $this->paidSettlement();
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION)->assertUnprocessable()->assertJsonValidationErrors('refund_funding_party');
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_funding_note');
        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'buyer', 'refund_funding_note' => 'The buyer should cover this themselves.'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_funding_party');

        $record->refresh();
        $this->assertNull($record->refunded_at);
        $this->assertNull($record->fault_party);
        $this->assertNull($record->return_shipping_fee_minor);
        $this->assertSame(0, SellerDebt::query()->count());
        $this->assertSame(0, LogisticsReturnCharge::query()->count());
        $this->assertSame([SellerSettlement::PAID, 9000, 9000], [$settlement->fresh()->status, (int) $settlement->fresh()->paid_amount_minor, (int) $settlement->fresh()->seller_payout_minor]);
    }

    public function test_an_explicit_seller_decision_charges_the_refund_but_no_return_trip(): void
    {
        $settlement = $this->paidSettlement();
        $record = $this->upheld();

        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'The Seller accepted liability in ticket FIN-9.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame([ParcelReturn::FAULT_SELLER, (int) $this->admin->id], [$record->fault_party, (int) $record->fault_set_by_admin_id]);
        $this->assertSame('The Seller accepted liability in ticket FIN-9.', $record->fault_note);
        $this->assertNull($record->return_shipping_fee_minor, 'No return trip happened, so none is charged.');
        $this->assertSame('seller', $record->refundHistory()->sole()->metadata['exception']['funding_party']);
        $this->assertFalse($record->refundHistory()->sole()->metadata['exception']['funding_established']);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'return_fault_set')->count());
        // ₱90 paid, ₱0 kept, plus the ₱5 refunded delivery fee; no ₱5 return trip on top.
        $this->assertSame(9500, (int) SellerDebt::query()->sole()->amount_minor);
        $this->assertSame([SellerSettlement::PAID, 9000], [$settlement->fresh()->status, (int) $settlement->fresh()->paid_amount_minor]);
        $this->assertSame(0, LogisticsReturnCharge::query()->where('reason', LogisticsReturnCharge::RETURN_TRIP)->count());
    }

    public function test_an_explicit_logistics_decision_leaves_the_seller_whole(): void
    {
        $this->paidSettlement();
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + ['refund_funding_party' => 'logistics', 'refund_funding_note' => 'The courier admitted mishandling the parcel.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(ParcelReturn::FAULT_LOGISTICS, $record->fresh()->fault_party);
        $this->assertSame(0, SellerDebt::query()->count());
        $this->assertSame(10500, LogisticsReturnCharge::query()->where('reason', LogisticsReturnCharge::TRANSIT_DAMAGE_REFUND)->sole()->amount_minor);
    }

    public function test_an_earlier_admin_payer_decision_is_preserved_and_a_conflicting_one_is_refused(): void
    {
        $this->paidSettlement();
        $record = $this->requested();
        $this->actingAs($this->admin, 'admin')->post(route('admin.returns.fault', $record), [
            'fault_party' => ParcelReturn::FAULT_LOGISTICS, 'fault_note' => 'Courier crushed the box in transit.',
        ])->assertSessionHasNoErrors();
        app(ParcelReturnService::class)->rejectBySeller($record->fresh(), $this->seller, 'The item was fine when shipped.');

        $this->recordRefund($record->fresh(), self::EXCEPTION + ['refund_funding_party' => 'seller', 'refund_funding_note' => 'Trying to move this to the Seller.'])
            ->assertConflict()->assertJsonValidationErrors('refund_funding_party');
        $this->assertNull($record->fresh()->refunded_at);

        $this->recordRefund($record->fresh(), self::EXCEPTION)->assertRedirect()->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame(ParcelReturn::FAULT_LOGISTICS, $record->fault_party);
        $this->assertTrue($record->refundHistory()->sole()->metadata['exception']['funding_established']);
        $this->assertSame(0, SellerDebt::query()->count());
    }

    public function test_approved_returns_keep_the_existing_seller_default_and_return_trip(): void
    {
        $record = $this->approved();

        $this->recordRefund($record)->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(ParcelReturn::FAULT_SELLER, $record->fault_party);
        $this->assertNull($record->fault_set_by_admin_id);
        $this->assertSame(500, $record->return_shipping_fee_minor);
    }

    public function test_admin_page_asks_who_pays_only_when_it_is_not_established(): void
    {
        $record = $this->rejected();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertSee('name="refund_funding_party"', false)->assertSee('An exception does not make the Seller pay by default.');

        $record->forceFill(['fault_party' => ParcelReturn::FAULT_LOGISTICS, 'fault_set_by_admin_id' => $this->admin->id, 'fault_set_at' => now()])->save();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertSee('name="refund_exception_basis"', false)->assertDontSee('name="refund_funding_party"', false)
            ->assertSee('Who pays was decided by an admin');
    }
}
