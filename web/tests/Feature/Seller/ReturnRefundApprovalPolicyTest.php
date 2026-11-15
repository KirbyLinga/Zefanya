<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Models\Shared\Voucher;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Audit 1.13 refund policy: an ordinary refund needs an approved buyer return. A pending,
 * rejected or upheld return needs a documented exception (legally required or separately
 * approved). Platform-funded vouchers are never charged to the Seller on a refund.
 */
class ReturnRefundApprovalPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EXCEPTION = ['refund_exception_basis' => 'legal_requirement', 'refund_exception_note' => 'Consumer Act refund ordered by DTI case 2026-114.'];

    private const SELLER_FUNDS = ['refund_funding_party' => 'seller', 'refund_funding_note' => 'The Seller accepted liability in ticket FIN-7.'];

    private SellerOrder $parcel;

    private Seller $seller;

    private Buyer $buyer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->admin = Admin::factory()->create(['role' => 'admin']);
        $this->parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->parcel->forceFill(['shipping_fee_minor' => 0])->save();
        StatusHistory::query()->create([
            'seller_order_id' => $this->parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        Payment::factory()->create(['seller_order_id' => $this->parcel->id, 'amount_minor' => 10000])
            ->recordCodCollection(10000, 'rider', 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function requested(): ParcelReturn
    {
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
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.reject', $record), ['reason' => 'The item was fine when shipped.'])
            ->assertSessionHasNoErrors();

        return $record->fresh();
    }

    private function upheld(): ParcelReturn
    {
        $record = $this->rejected();
        app(ParcelReturnService::class)->escalateByBuyer($record, $this->buyer, 'The photos show the damage the seller denied.');
        app(ParcelReturnService::class)->resolveDispute($record->fresh(), $this->admin, ParcelReturn::DISPUTE_UPHELD, 'The photos do not show the claimed damage.');
        $this->assertSame(ParcelReturn::DISPUTE_UPHELD, $record->fresh()->dispute_outcome);

        return $record->fresh();
    }

    /** @param array<string, string> $extra */
    private function recordRefund(ParcelReturn $record, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund', $record), $extra + [
            'reference' => 'GCASH-7781', 'amount' => '100.00', 'recipient' => 'Juana Cruz 0917', 'paid_on' => now('Asia/Manila')->toDateString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function moneySnapshot(ParcelReturn $record): array
    {
        $fresh = $record->fresh();

        return [$fresh->refunded_at, $fresh->refund_amount_minor, $fresh->fault_party, $fresh->events()->count(),
            SellerDebt::query()->count(), OperationsAuditLog::query()->count(),
            $this->parcel->fresh()->only(['commission_minor', 'seller_net_minor'])];
    }

    #[TestWith(['pending'])]
    #[TestWith(['rejected'])]
    #[TestWith(['upheld'])]
    public function test_a_pending_rejected_or_upheld_return_rejects_an_ordinary_refund(string $state): void
    {
        $record = match ($state) {
            'pending' => $this->requested(),
            'rejected' => $this->rejected(),
            'upheld' => $this->upheld(),
        };
        $this->assertFalse($record->refundApproved());
        $before = $this->moneySnapshot($record);

        $this->recordRefund($record)->assertUnprocessable()->assertJsonValidationErrors('refund_exception_basis');
        $this->recordRefund($record, ['refund_exception_basis' => 'legal_requirement', 'refund_exception_note' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_exception_note');
        $this->recordRefund($record, ['refund_exception_basis' => 'buyer_asked', 'refund_exception_note' => 'The buyer asked again nicely.'])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_exception_basis');

        $this->assertEquals($before, $this->moneySnapshot($record));
    }

    public function test_a_documented_exception_records_the_refund_with_history_and_audit(): void
    {
        $record = $this->rejected();

        $this->recordRefund($record, self::EXCEPTION + self::SELLER_FUNDS)->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(10000, $record->refund_amount_minor);
        $this->assertSame(ParcelReturnStatus::Rejected, $record->status);
        $event = $record->refundHistory()->sole();
        $this->assertSame('legal_requirement', $event->metadata['exception']['basis']);
        $this->assertSame(self::EXCEPTION['refund_exception_note'], $event->metadata['exception']['note']);
        $this->assertSame(ParcelReturnStatus::Rejected->value, $event->metadata['exception']['return_status']);
        $this->assertSame(ParcelReturn::REJECTED, $event->metadata['exception']['seller_decision']);
        $audit = OperationsAuditLog::query()->where('action', 'return_refund_exception')->sole();
        $this->assertSame(['admin', (int) $this->admin->id], [$audit->actor_type, (int) $audit->actor_id]);
        $this->assertSame(self::EXCEPTION['refund_exception_note'], $audit->reason);
    }

    public function test_an_approved_return_needs_no_exception_and_records_none(): void
    {
        $record = $this->requested();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertTrue($record->fresh()->refundApproved());

        $this->recordRefund($record)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('exception', $record->refundHistory()->sole()->metadata);
        $this->assertFalse(OperationsAuditLog::query()->where('action', 'return_refund_exception')->exists());
    }

    public function test_an_overturned_dispute_counts_as_approved(): void
    {
        $record = $this->rejected();
        app(ParcelReturnService::class)->escalateByBuyer($record, $this->buyer, 'The photos show the damage the seller denied.');
        app(ParcelReturnService::class)->resolveDispute($record->fresh(), $this->admin, ParcelReturn::DISPUTE_OVERTURNED, 'The damage photos match the buyer description.');

        $this->assertTrue($record->fresh()->refundApproved());
        $this->recordRefund($record)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_moderators_still_cannot_record_even_an_exception_refund(): void
    {
        $record = $this->rejected();
        $this->actingAs(Admin::factory()->create(['role' => 'moderator']), 'admin')
            ->postJson(route('admin.returns.refund', $record), self::EXCEPTION + [
                'reference' => 'GCASH-7781', 'amount' => '100.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString(),
            ])->assertForbidden();
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_admin_page_asks_for_the_exception_only_when_the_return_is_not_approved(): void
    {
        $record = $this->requested();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertSee('name="refund_exception_basis"', false)->assertSee('Legally required refund');

        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()->assertSee(route('admin.returns.refund', $record), false)->assertDontSee('name="refund_exception_basis"', false);
    }

    public function test_a_full_refund_never_charges_the_seller_the_platform_funded_voucher(): void
    {
        // ₱100 item, ₱20 platform-funded voucher: the buyer paid ₱80, the Seller was paid ₱90 (full price less 10%).
        $this->parcel->order->forceFill(['discount_minor' => 2000])->save();
        $this->parcel->forceFill(['voucher_discount_minor' => 2000, 'voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
            'commission_bps' => 1000, 'commission_minor' => 1000, 'seller_net_minor' => 9000])->save();
        $payment = $this->parcel->payment;
        $payment->forceFill(['amount_minor' => 8000, 'collected_amount_minor' => 8000])->save();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $remittance = (new CodRemittance)->forceFill(['payment_id' => $payment->id, 'seller_order_id' => $this->parcel->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 8000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'confirmed_by_id' => $provider->id, 'confirmed_at' => now(),
            'received_amount_minor' => 8000, 'zefanya_received_at' => now()]);
        $remittance->save();
        (new SellerSettlement)->forceFill(['seller_order_id' => $this->parcel->id, 'cod_remittance_id' => $remittance->id,
            'payment_id' => $payment->id, 'seller_id' => $this->seller->id, 'collected_amount_minor' => 8000,
            'shipping_allocation_minor' => 0, 'status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();

        $record = $this->requested();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->recordRefund($record, ['amount' => '80.00'])->assertRedirect()->assertSessionHasNoErrors();

        // The Seller loses only what the buyer got back (₱80, less its commission); the ₱20 platform subsidy is not charged.
        $breakdown = app(SellerSettlementService::class)->returnBreakdown($this->parcel->fresh(['parcelReturn']));
        $this->assertSame(8000, $breakdown['refunded_items_minor']);
        $this->assertSame(1800, $breakdown['kept_net_minor']);
        $this->assertSame(9000 - 1800, (int) SellerDebt::query()->sole()->amount_minor);
    }
}
