<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
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
use App\Models\Shared\FinancialReference;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelReturnService;
use App\Support\RuntimeSchema;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RefundPaymentPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    private ParcelReturn $record;

    private CodRemittance $remittance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        $this->admin = Admin::factory()->create(['role' => 'super_admin']);
        // Activate only this isolated test schema; the live database is never migrated.
        (require base_path('tests/Support/LegacyMigrations/2026_11_14_120000_activate_refund_payment_policy.php'))->up();
        $this->clearSchemaMemo();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['subtotal_minor' => 45000]);
        $order->forceFill(['shipping_fee_minor' => 5000, 'commission_bps' => 1000, 'commission_minor' => 4500,
            'seller_net_minor' => 40500, 'commission_finalized_at' => now()])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 50000]);
        $payment->recordCodCollection(50000, 'rider', $courier->id);
        $this->remittance = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 50000,
            'status' => CodRemittance::PENDING_REMITTANCE]);
        $this->remittance->snapshotAllocation();
        $this->remittance->save();
        $this->record = (new ParcelReturn)->forceFill(['seller_order_id' => $order->id, 'status' => ParcelReturnStatus::Requested,
            'requested_at' => now(), 'reason' => 'Wrong item received', 'seller_response_due_at' => now()->addDays(3),
            'seller_decision' => ParcelReturn::APPROVED, 'seller_decided_at' => now(), 'fault_party' => 'seller',
            'financial_review_required' => true, 'return_shipping_fee_minor' => 5000, 'delivery_attempts' => 0]);
        $this->record->save();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->clearSchemaMemo();
    }

    private function clearSchemaMemo(): void
    {
        foreach (['columns', 'tables'] as $property) {
            (new \ReflectionProperty(RuntimeSchema::class, $property))->setValue(null, []);
        }
    }

    private function proof(string $name = 'payment.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    private function custodyEvent(string $key, array $metadata = [], ?\Illuminate\Support\Carbon $at = null, string $actor = 'logistics'): void
    {
        (new \App\Models\Logistics\ParcelReturnEvent)->forceFill(['parcel_return_id' => $this->record->id, 'event_key' => $key,
            'actor_type' => $actor, 'actor_id' => 1, 'occurred_at' => $at ?? now(), 'metadata' => $metadata])->save();
    }

    /** Recorded custody chain: rider collection, then station receipt at the same station. */
    private function custody(): void
    {
        $meta = ['station_hub_id' => 7, 'provider_id' => 3];
        $this->custodyEvent('collected_from_buyer', $meta, now()->subHour(), 'rider');
        $this->custodyEvent('station_received', $meta, now()->subMinutes(30));
    }

    private function receipt(bool $custody = true): void
    {
        if ($custody) {
            $this->custody();
        }
        // Represents the fields written only after the owning Seller verifies handover.
        $this->record->forceFill(['seller_received_at' => now(), 'received_by_seller_id' => $this->record->sellerOrder->seller_id])->save();
    }

    private function authorize(array $extra = [], string $payer = 'seller')
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.authorize', $this->record), $extra + [
            'amount' => '500.00', 'payer' => $payer, 'authorization_note' => 'Finance approves this refund after reviewing the case evidence.',
        ]);
    }

    private function early(string $payer = 'seller', string $amount = '500.00'): void
    {
        $this->authorize(['amount' => $amount, 'refund_exception_basis' => 'separate_approval', 'exception_proof' => $this->proof('exception.pdf')], $payer)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->record->refresh();
    }

    private function submit(array $extra = [])
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.evidence', $this->record), $extra + [
            'authorization_id' => $this->record->currentRefundAuthorization()->id,
            'amount' => number_format($this->record->currentRefundAuthorization()->metadata['amount_minor'] / 100, 2, '.', ''),
            'payer' => $this->record->currentRefundAuthorization()->metadata['payer'], 'reference' => 'BANK-TRANSFER-12345',
            'recipient' => 'Private recipient account', 'paid_on' => now('Asia/Manila')->toDateString(), 'proof' => $this->proof(),
        ]);
    }

    private function confirm(array $extra = [])
    {
        return $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.confirm', $this->record), $extra + [
            'evidence_id' => $this->record->events()->where('event_key', 'like', 'refund_evidence_submitted%')->reorder('id', 'desc')->first()->id,
            'verification_note' => 'Reviewed the payment provider receipt and matched amount, recipient and transfer status.',
        ]);
    }

    public function test_approval_authorization_and_submission_do_not_mark_paid_or_book_expenses(): void
    {
        $this->assertNull($this->record->refunded_at);
        $this->early('platform');
        $this->assertNull($this->record->fresh()->refunded_at);
        $this->assertSame(0, PlatformRefundExpense::count());
        $this->submit()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->record->fresh()->refunded_at);
        $this->assertSame(0, PlatformRefundExpense::count());
        $this->assertSame('Payment evidence submitted - awaiting verification', $this->record->fresh()->refundPaymentLabel());
    }

    public function test_normal_authorization_without_receipt_is_rejected(): void
    {
        $this->authorize()->assertUnprocessable()->assertJsonValidationErrors('refund_exception_basis');
        $this->assertNull($this->record->fresh()->refunded_at);
        $this->assertSame(0, $this->record->events()->count());
    }

    public function test_a_voided_reference_reaches_a_new_destination_only_through_verified_evidence(): void
    {
        $this->receipt();
        $this->authorize()->assertRedirect()->assertSessionHasNoErrors();
        $this->submit()->assertRedirect()->assertSessionHasNoErrors();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.void', $this->record), [
            'void_reason' => 'The transfer bounced from the private recipient account.',
            'refund_revision' => (int) $this->record->refundHistory()->max('id'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->record->refresh();

        // The same bank reference to another account, recorded without evidence: refused.
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund', $this->record), [
            'reference' => 'BANK-TRANSFER-12345', 'amount' => '500.00', 'recipient' => 'Another account',
            'paid_on' => now('Asia/Manila')->toDateString(), 'refund_revision' => (int) $this->record->refundHistory()->max('id'),
        ])->assertStatus(422);
        $this->assertNull($this->record->fresh()->refunded_at);

        // With submitted proof that a Super Admin verified, the correction is accepted and the evidence kept.
        $this->authorize()->assertRedirect()->assertSessionHasNoErrors();
        $this->record->refresh();
        $this->submit(['recipient' => 'Another account'])->assertRedirect()->assertSessionHasNoErrors();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Another account', $this->record->fresh()->refund_recipient);
        $correction = FinancialReference::query()->whereNotNull('reuses_id')->sole();
        $this->assertSame('another account', $correction->destination);
        $this->assertStringStartsWith('refund_evidence:', $correction->evidence);
        $this->assertSame('private recipient account', FinancialReference::query()->find($correction->reuses_id)->destination);
    }

    public function test_verified_seller_receipt_allows_normal_authorization_and_confirmed_payment(): void
    {
        $this->receipt();
        $this->authorize()->assertRedirect()->assertSessionHasNoErrors();
        $this->submit()->assertRedirect()->assertSessionHasNoErrors();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->record->fresh()->refunded_at);
        $this->assertSame(1, (int) $this->record->fresh()->refund_policy_version);
        $this->assertSame('Refund payment confirmed', $this->record->fresh()->refundPaymentLabel());
    }

    public function test_authorized_early_exception_can_be_paid_without_seller_receipt(): void
    {
        $this->admin->forceFill(['role' => 'super_admin'])->save();
        $this->early();
        // An Admin may submit the payment evidence, but only a Super Admin confirms it.
        $this->admin->forceFill(['role' => 'admin'])->save();
        $this->submit()->assertRedirect();
        $this->confirm()->assertForbidden();
        $this->assertNull($this->record->fresh()->refunded_at);
        $this->admin->forceFill(['role' => 'super_admin'])->save();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->record->fresh()->seller_received_at);
        $this->assertNotNull($this->record->fresh()->refunded_at);
        $event = $this->record->refundHistory()->sole();
        $this->assertSame('separate_approval', $event->metadata['exception']['basis']);
        $this->assertSame('admin_evidence_review', $event->metadata['verification']);
    }

    #[TestWith(['moderator'])]
    #[TestWith(['support'])]
    public function test_non_financial_admin_cannot_authorize_submit_confirm_or_read_evidence(string $role): void
    {
        $this->early();
        $this->submit()->assertRedirect();
        $auth = $this->record->currentRefundAuthorization();
        $this->admin->forceFill(['role' => $role])->save();
        $this->authorize()->assertForbidden();
        $this->submit()->assertForbidden();
        $this->confirm()->assertForbidden();
        $this->get(route('admin.returns.refund.evidence-file', [$this->record, $auth->id]))->assertForbidden();
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    public function test_early_exception_requires_real_private_evidence_and_justification(): void
    {
        $this->authorize(['refund_exception_basis' => 'legal_requirement'])->assertUnprocessable()->assertJsonValidationErrors('exception_proof');
        $this->authorize(['authorization_note' => 'short', 'refund_exception_basis' => 'legal_requirement', 'exception_proof' => $this->proof()])
            ->assertUnprocessable()->assertJsonValidationErrors('authorization_note');
        $this->assertSame(0, $this->record->events()->count());
    }

    public function test_legal_exception_remedy_does_not_require_return_approval_or_fabricated_receipt(): void
    {
        $this->record->forceFill(['status' => ParcelReturnStatus::Rejected, 'seller_decision' => ParcelReturn::REJECTED,
            'fault_party' => null, 'fault_set_by_admin_id' => null])->save();
        $this->authorize(['refund_exception_basis' => 'legal_requirement', 'exception_proof' => $this->proof('legal-evidence.pdf')], 'platform')
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->record->fresh()->refundObligationEstablished());
        $this->assertTrue(ParcelReturn::query()->awaitingRefundRecording()->whereKey($this->record->id)->exists());
        $this->submit()->assertRedirect();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ParcelReturn::REJECTED, $this->record->fresh()->seller_decision);
        $this->assertNull($this->record->fresh()->seller_received_at);
        $this->assertNotNull($this->record->fresh()->refunded_at);
    }

    public function test_missing_invalid_deleted_evidence_or_unreviewed_note_cannot_confirm_paid(): void
    {
        $this->early();
        $this->submit(['amount' => '499.99'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->submit(['payer' => 'logistics'])->assertUnprocessable()->assertJsonValidationErrors('payer');
        $this->submit(['proof' => null])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->submit(['proof' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a PDF')])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->submit()->assertRedirect();
        $this->confirm(['verification_note' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('verification_note');
        Storage::disk('local')->delete($this->record->currentRefundSubmission()->metadata['proof_path']);
        $this->confirm()->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    public function test_old_recording_endpoint_and_direct_service_cannot_bypass_policy(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund', $this->record), [
            'reference' => 'TYPED-ONLY', 'amount' => '500.00', 'recipient' => 'Some account', 'paid_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('refund');
        try {
            app(ParcelReturnService::class)->recordRefund($this->record, $this->admin, 'TYPED-ONLY', 50000, 'Some account', now()->toDateString());
            $this->fail('Direct recording bypassed authorization and verification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    public function test_over_collection_authorization_and_changed_collection_are_rejected(): void
    {
        $this->authorize(['amount' => '500.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->early();
        $this->submit()->assertRedirect();
        $this->record->sellerOrder->payment->forceFill(['collected_amount_minor' => 40000])->save();
        $this->confirm()->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    #[TestWith(['seller'])]
    #[TestWith(['logistics'])]
    #[TestWith(['platform'])]
    public function test_payer_accounting_and_pending_cod_remain_separate(string $payer): void
    {
        $before = $this->remittance->fresh()->getAttributes();
        $financials = $this->record->sellerOrder->only(['commission_bps', 'commission_minor', 'seller_net_minor']);
        $this->early($payer);
        $this->submit()->assertRedirect();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($before, $this->remittance->fresh()->getAttributes());
        $this->assertSame($payer, $this->record->fresh()->fault_party);
        $this->assertSame($payer === 'platform' ? 50000 : 0, (int) PlatformRefundExpense::sum('amount_minor'));
        if ($payer !== 'seller') {
            $this->assertSame(0, SellerDebt::count());
        }
        if ($payer === 'platform') {
            $this->assertSame(0, LogisticsReturnCharge::count());
            $this->assertSame($financials, $this->record->sellerOrder->fresh()->only(['commission_bps', 'commission_minor', 'seller_net_minor']));
        }
    }

    #[TestWith(['seller'])]
    #[TestWith(['logistics'])]
    #[TestWith(['platform'])]
    public function test_confirmed_payment_preserves_paid_settlement_and_posts_only_the_correct_payer_liability(string $payer): void
    {
        $order = $this->record->sellerOrder;
        $this->remittance->forceFill(['status' => CodRemittance::REMITTED_TO_LOGISTICS, 'confirmed_by_id' => $this->remittance->logistics_provider_id,
            'confirmed_at' => now(), 'received_amount_minor' => 50000, 'zefanya_received_at' => now()])->save();
        $settlement = (new SellerSettlement)->forceFill(['seller_order_id' => $order->id, 'cod_remittance_id' => $this->remittance->id,
            'payment_id' => $order->payment->id, 'seller_id' => $order->seller_id, 'collected_amount_minor' => 50000,
            'shipping_allocation_minor' => 5000, 'commission_bps' => 1000, 'commission_minor' => 4500, 'seller_payout_minor' => 40500,
            'status' => SellerSettlement::PAID, 'paid_amount_minor' => 40500, 'financials_finalized_at' => now()]);
        $settlement->save();
        $before = $settlement->fresh()->getAttributes();
        $this->early($payer);
        $this->submit()->assertRedirect();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $settlement->fresh()->getAttributes());
        $this->assertSame($payer === 'seller' ? 50500 : 0, (int) SellerDebt::sum('amount_minor'));
        $this->assertSame($payer === 'logistics' ? 50000 : 0, (int) LogisticsReturnCharge::sum('amount_minor'));
        $this->assertSame($payer === 'platform' ? 50000 : 0, (int) PlatformRefundExpense::sum('amount_minor'));
    }

    public function test_confirmation_and_authorization_are_not_replayed_and_payer_is_frozen(): void
    {
        $this->early('platform');
        $this->authorize()->assertConflict();
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.fault', $this->record), ['fault_party' => 'seller', 'fault_note' => 'Attempt to replace the authorized payer.'])->assertConflict();
        $this->submit()->assertRedirect();
        $this->confirm()->assertRedirect();
        $this->confirm()->assertConflict();
        $this->assertSame(1, PlatformRefundExpense::count());
        $this->assertSame(1, $this->record->refundHistory()->count());
    }

    public function test_superseded_evidence_and_other_return_evidence_cannot_be_confirmed(): void
    {
        $this->early();
        $this->submit()->assertRedirect();
        $old = $this->record->currentRefundSubmission()->id;
        $this->submit(['reference' => 'CORRECTED-BANK-REFERENCE'])->assertRedirect();
        $this->confirm(['evidence_id' => $old])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $other = (new ParcelReturn)->forceFill(['seller_order_id' => SellerOrder::factory()->create()->id, 'status' => ParcelReturnStatus::Requested, 'reason' => 'Wrong item', 'requested_at' => now(), 'delivery_attempts' => 0]);
        $other->save();
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.confirm', $other), ['evidence_id' => $old, 'verification_note' => 'Attempt to confirm evidence for another return.'])->assertNotFound();
        $this->confirm()->assertRedirect();
    }

    public function test_partial_refund_requires_final_reason_and_legacy_paid_fallback_is_not_new_collection_evidence(): void
    {
        $this->record->sellerOrder->payment->forceFill(['collected_at' => null, 'collected_amount_minor' => null])->save();
        $this->authorize()->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->record->sellerOrder->payment->forceFill(['collected_at' => now(), 'collected_amount_minor' => 50000])->save();
        $this->early('seller', '300.00');
        $this->submit()->assertRedirect();
        $this->confirm()->assertUnprocessable()->assertJsonValidationErrors('refund_reason');
        $this->submit(['refund_reason' => 'Final partial refund agreed with buyer after review.'])->assertRedirect();
        $this->confirm()->assertRedirect();
        $this->assertSame(30000, $this->record->fresh()->refund_amount_minor);
        $buyer = $this->record->sellerOrder->order->buyer;
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $this->record->sellerOrder->order))->assertOk()->assertSee('Final partial refund agreed with buyer after review.');
    }

    public function test_final_partial_refund_and_void_require_fresh_authorization_for_replacement(): void
    {
        $this->early('platform', '300.00');
        $this->submit(['refund_reason' => 'Final partial refund agreed after inspecting the damage.'])->assertRedirect();
        $this->confirm()->assertRedirect();
        $this->confirm()->assertConflict();
        $revision = $this->record->refundHistory()->reorder('id', 'desc')->first()->id;
        $oldEvidence = $this->record->events()->where('event_key', 'like', 'refund_evidence_submitted%')->reorder('id', 'desc')->first()->id;
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.refund.void', $this->record), ['void_reason' => 'The provider confirmed the transfer failed.', 'refund_revision' => $revision])->assertRedirect();
        $this->assertSame('Refund transfer failed - payment pending review', $this->record->fresh()->refundPaymentLabel());
        $this->confirm(['evidence_id' => $oldEvidence])->assertConflict();
        $this->early('platform');
        $this->submit(['reference' => 'REPLACEMENT-TRANSFER'])->assertRedirect();
        $this->confirm()->assertRedirect();
        $this->assertSame(50000, $this->record->fresh()->refund_amount_minor);
        $this->assertSame(2, PlatformRefundExpense::where('entry_type', PlatformRefundExpense::EXPENSE)->count());
        $this->assertSame(1, PlatformRefundExpense::where('entry_type', PlatformRefundExpense::REVERSAL)->count());
    }

    public function test_authorization_cannot_be_discarded_by_pre_collection_cancellation(): void
    {
        $this->early();
        $this->assertFalse($this->record->fresh()->canBuyerCancel());
        $this->actingAs($this->admin, 'admin')->postJson(route('admin.returns.close', $this->record), ['close_reason' => 'Attempt to discard an authorized refund.'])->assertConflict();
        $this->assertNotNull($this->record->currentRefundAuthorization());
    }

    public function test_public_seller_buyer_and_api_projections_do_not_expose_payment_evidence(): void
    {
        $this->early();
        $this->submit()->assertRedirect();
        $seller = $this->record->sellerOrder->seller;
        $seller->forceFill(['status' => 'approved'])->save();
        $this->actingAs($seller, 'seller')->get(route('seller.returns.index'))->assertOk()
            ->assertSee('Payment evidence submitted')->assertDontSee('Private recipient account')->assertDontSee('BANK-TRANSFER-12345');
        $buyer = $this->record->sellerOrder->order->buyer;
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $this->record->sellerOrder->order))->assertOk()
            ->assertDontSee('Private recipient account')->assertDontSee('BANK-TRANSFER-12345');
        $this->app['auth']->forgetGuards();
        $token = $buyer->createToken('refund-policy-test', ['buyer'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/buyer/orders/'.$this->record->sellerOrder->order_id)->assertOk();
        $this->assertStringNotContainsString('Private recipient account', $response->getContent());
        $this->assertStringNotContainsString('refund-proofs', $response->getContent());
    }

    public function test_confirmed_proof_stays_restricted_through_the_legacy_proof_route(): void
    {
        $this->early();
        $this->submit()->assertRedirect();
        $this->confirm()->assertRedirect();
        $this->admin->forceFill(['role' => 'moderator'])->save();
        $this->get(route('admin.returns.refund-proof', $this->record))->assertForbidden();
    }

    public function test_historical_record_is_readable_but_not_relabelled_as_verified(): void
    {
        $this->record->forceFill(['refunded_at' => now(), 'refund_amount_minor' => 50000, 'refund_reference' => '5040.00'])->save();
        $this->assertStringContainsString('Historical refund recorded', $this->record->fresh()->refundPaymentLabel());
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))->assertOk()->assertSee('payment evidence requires manual review');
        $this->assertNull($this->record->fresh()->refund_policy_version);
    }

    public function test_admin_form_stages_and_private_proof_access(): void
    {
        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))->assertOk()->assertSee('Exception under review');
        $this->early();
        $this->get(route('admin.returns.index'))->assertOk()->assertSee('Refund authorized')->assertDontSee('Private recipient account');
        $this->submit()->assertRedirect();
        $this->get(route('admin.returns.index'))->assertOk()->assertSee('Payment evidence submitted')->assertSee('How was payment verified?');
        $this->get(route('admin.returns.refund.evidence-file', [$this->record, $this->record->currentRefundSubmission()->id]))->assertOk();
    }

    #[TestWith(['buyer'])]
    #[TestWith(['seller'])]
    #[TestWith(['logistics'])]
    public function test_other_roles_cannot_write_or_read_admin_payment_evidence(string $guard): void
    {
        $user = match ($guard) {
            'buyer' => Buyer::factory()->create(), 'seller' => Seller::factory()->create(), default => LogisticsProvider::factory()->create()
        };
        $this->actingAs($user, $guard)->postJson(route('admin.returns.refund.authorize', $this->record), [])->assertUnauthorized();
        $this->postJson(route('admin.returns.refund.evidence', $this->record), [])->assertUnauthorized();
        $this->postJson(route('admin.returns.refund.confirm', $this->record), [])->assertUnauthorized();
        $this->getJson(route('admin.returns.refund.evidence-file', [$this->record, 1]))->assertUnauthorized();
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    public function test_normal_authorization_blocked_when_station_receipt_is_missing_but_exception_still_works(): void
    {
        $this->receipt(custody: false);
        $this->authorize()->assertJsonValidationErrors('refund_exception_basis');
        $this->assertNull($this->record->fresh()->currentRefundAuthorization());
        $this->early();
        $this->assertNotNull($this->record->currentRefundAuthorization());
    }

    public function test_rider_collection_without_station_receipt_blocks_normal_path(): void
    {
        $this->custodyEvent('collected_from_buyer', ['station_hub_id' => 7, 'provider_id' => 3], now()->subHour(), 'rider');
        $this->receipt(custody: false);
        $this->assertSame('No recorded Logistics station receipt.', $this->record->fresh()->refundCustodyProblem());
        $this->authorize()->assertJsonValidationErrors('refund_exception_basis');
    }

    public function test_valid_custody_chain_allows_normal_refund_and_status_text_alone_is_not_enough(): void
    {
        $this->record->forceFill(['status' => ParcelReturnStatus::Completed, 'collected_at' => now()->subHour()])->save();
        $this->receipt(custody: false);
        $this->assertNotNull($this->record->fresh()->refundCustodyProblem());
        $this->custody();
        $this->assertNull($this->record->fresh()->refundCustodyProblem());
        $this->authorize()->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_out_of_order_and_mismatched_custody_events_are_rejected(): void
    {
        $meta = ['station_hub_id' => 7, 'provider_id' => 3];
        $this->custodyEvent('station_received', $meta, now()->subHour());
        $this->custodyEvent('collected_from_buyer', $meta, now()->subMinutes(10), 'rider');
        $this->assertSame('Station receipt is recorded before the rider collection.', $this->record->fresh()->refundCustodyProblem());

        $this->record->events()->delete();
        $this->custodyEvent('collected_from_buyer', $meta, now()->subHour(), 'rider');
        $this->custodyEvent('station_received', ['station_hub_id' => 8, 'provider_id' => 3], now()->subMinutes(30));
        $this->assertSame('Station receipt does not match the recorded collection station.', $this->record->fresh()->refundCustodyProblem());

        $this->record->events()->delete();
        $this->custody();
        $this->record->forceFill(['origin_arrived_at' => now()])->save();
        $this->custodyEvent('at_origin', $meta, now()->subHours(3));
        $this->assertSame('Origin receipt is recorded before the station receipt.', $this->record->fresh()->refundCustodyProblem());
    }

    public function test_duplicate_station_receipts_are_tolerated_only_when_identical(): void
    {
        $this->custody();
        $this->custodyEvent('station_received_2', ['station_hub_id' => 7, 'provider_id' => 3]);
        $this->assertNull($this->record->fresh()->refundCustodyProblem());
        $this->custodyEvent('station_received_3', ['station_hub_id' => 9, 'provider_id' => 3]);
        $this->assertSame('Conflicting station receipts are recorded.', $this->record->fresh()->refundCustodyProblem());
    }

    public function test_direct_seller_return_route_needs_only_the_origin_event_and_no_station_receipt(): void
    {
        $this->record->forceFill(['seller_response_due_at' => null])->save();
        $this->receipt(custody: false);
        $this->assertSame('No recorded origin-station receipt for this return.', $this->record->fresh()->refundCustodyProblem());
        $this->custodyEvent('at_origin');
        $this->assertSame('No recorded origin-station receipt for this return.', $this->record->fresh()->refundCustodyProblem(), 'an event alone, without verified origin arrival, proves nothing');
        $this->record->forceFill(['origin_arrived_at' => now()])->save();
        $this->assertNull($this->record->fresh()->refundCustodyProblem());
        $this->authorize()->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_at_origin_event_never_substitutes_for_genuine_seller_receipt(): void
    {
        $this->record->forceFill(['seller_response_due_at' => null, 'origin_arrived_at' => now()])->save();
        $this->custodyEvent('at_origin');
        $this->assertNull($this->record->fresh()->refundCustodyProblem());
        // Custody is sound but the Seller has not confirmed receipt: normal path stays closed.
        $this->assertSame('The owning Seller has not confirmed receipt.', $this->record->fresh()->refundNormalPathBlocker());
        $this->authorize()->assertJsonValidationErrors('refund_exception_basis');
        // Receipt recorded against some other Seller is not genuine either.
        $other = Seller::factory()->create();
        $this->record->forceFill(['seller_received_at' => now(), 'received_by_seller_id' => $other->id])->save();
        $this->assertSame('The owning Seller has not confirmed receipt.', $this->record->fresh()->refundNormalPathBlocker());
        $this->authorize()->assertJsonValidationErrors('refund_exception_basis');
    }

    public function test_at_origin_without_arrival_blocks_buyer_collected_chain_and_cross_provider_stations_must_match(): void
    {
        $this->custody();
        $this->custodyEvent('at_origin', ['station_hub_id' => 7], now());
        $this->assertSame('Origin receipt event has no matching verified origin arrival.', $this->record->fresh()->refundCustodyProblem());
        $this->record->forceFill(['origin_arrived_at' => now()])->save();
        $this->assertNull($this->record->fresh()->refundCustodyProblem(), 'intermediate hub moves after station receipt do not change the chain');
        $this->record->events()->delete();
        $this->custodyEvent('collected_from_buyer', ['station_hub_id' => 7, 'provider_id' => 3], now()->subHour(), 'rider');
        $this->custodyEvent('station_received', ['station_hub_id' => 7, 'provider_id' => 4], now()->subMinutes(30));
        $this->assertSame('Station receipt does not match the recorded collection station.', $this->record->fresh()->refundCustodyProblem(), 'a different provider cannot acknowledge the collection');
    }

    public function test_confirmation_rejects_custody_that_became_stale_or_conflicting_after_authorization(): void
    {
        $this->receipt();
        $this->authorize()->assertRedirect();
        $this->submit()->assertRedirect();
        $this->custodyEvent('station_received_2', ['station_hub_id' => 99, 'provider_id' => 3]);
        $this->confirm()->assertJsonValidationErrors('refund');
        $this->assertNull($this->record->fresh()->refunded_at);
        $this->assertSame(0, $this->record->events()->where('event_key', 'like', 'refund_recorded%')->count());
        $this->record->events()->where('event_key', 'station_received_2')->delete();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->record->fresh()->refunded_at);
    }

    public function test_confirmation_rechecks_custody_for_a_normal_authorization(): void
    {
        $this->receipt();
        $this->authorize()->assertRedirect();
        $this->submit()->assertRedirect();
        $this->record->events()->where('event_key', 'station_received')->delete();
        $this->confirm()->assertJsonValidationErrors('refund');
        $this->assertNull($this->record->fresh()->refunded_at);
    }

    public function test_historical_refund_is_not_touched_by_the_custody_rule(): void
    {
        $this->record->forceFill(['refunded_at' => now()->subDay(), 'refund_amount_minor' => 50000, 'refund_reference' => 'OLD-1',
            'refund_recipient' => 'Buyer', 'refund_paid_on' => now()->subDay()->toDateString()])->save();
        $this->assertNull($this->record->fresh()->getAttribute('refund_policy_version'));
        $this->assertSame('Historical refund recorded', $this->record->fresh()->refundPaymentLabel());
    }

    public function test_single_admin_flow_records_actors_and_shows_a_nonblocking_non_bank_warning(): void
    {
        $this->receipt();
        $this->authorize()->assertRedirect();
        $this->submit()->assertRedirect();
        $record = $this->record->fresh();
        $actors = $record->refundStepActors();
        $this->assertSame($this->admin->id, (int) $actors['authorization']['admin_id']);
        $this->assertSame($this->admin->id, (int) $actors['evidence']['admin_id']);
        $this->assertNotNull($actors['evidence']['at']);
        $this->assertTrue($record->refundHandledByOneAdmin($this->admin->id));
        $this->assertFalse($record->refundHandledByOneAdmin($this->admin->id + 1));
        $this->actingAs($this->admin, 'admin');
        $html = view('admin.returns._refund-policy', ['record' => $record])->render();
        $this->assertStringContainsString('not independent verification of the bank transfer', $html);
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->record->fresh()->refunded_at);
        $this->assertStringContainsString('not independent bank verification', $this->record->fresh()->refundPaymentLabel(internal: true));
    }
}
