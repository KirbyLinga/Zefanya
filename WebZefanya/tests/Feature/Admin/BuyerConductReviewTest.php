<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\BuyerConductReport;
use App\Models\Shared\BuyerConductReview;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Admin\AccountStatusChanged;
use App\Notifications\Admin\BuyerConductAlert;
use App\Notifications\Buyer\ConductReviewDecision;
use App\Notifications\Seller\BuyerReportUpdated;
use App\Services\Admin\BuyerConductReportService;
use App\Services\Admin\BuyerConductReviewService;
use App\Services\Buyer\CodRiskService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Admin-controlled Buyer conduct review: alerts only, manual decisions, notifications after decisions. */
class BuyerConductReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function scan(): array
    {
        return app(BuyerConductReviewService::class)->scan();
    }

    private function parcel(Buyer $buyer, ?Order $order = null, OrderStatus $status = OrderStatus::Placed): SellerOrder
    {
        $order ??= Order::factory()->create(['buyer_id' => $buyer->id]);

        return SellerOrder::factory()->status($status)->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
        ]);
    }

    private function cancel(SellerOrder $parcel, string $actorType, int $actorId, ?\DateTimeInterface $at = null): StatusHistory
    {
        $row = StatusHistory::query()->create([
            'seller_order_id' => $parcel->id, 'from_status' => OrderStatus::Placed, 'to_status' => OrderStatus::Cancelled,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'source' => 'web', 'note' => 'Changed my mind',
        ]);
        if ($at !== null) {
            $row->forceFill(['created_at' => $at])->save();
        }

        return $row;
    }

    private function buyerCancellations(Buyer $buyer, int $orders, ?\DateTimeInterface $at = null): void
    {
        for ($i = 0; $i < $orders; $i++) {
            $this->cancel($this->parcel($buyer, status: OrderStatus::Cancelled), 'buyer', $buyer->id, $at);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function buyerReturn(Buyer $buyer, array $attributes): ParcelReturn
    {
        $record = new ParcelReturn;
        $record->forceFill($attributes + [
            'seller_order_id' => $this->parcel($buyer, status: OrderStatus::Completed)->id,
            'status' => ParcelReturnStatus::Rejected, 'reason' => 'Item arrived damaged',
            'requested_at' => now()->subDays(5), 'seller_response_due_at' => now()->subDays(2),
        ])->save();

        return $record;
    }

    private function upheld(Buyer $buyer, ?\DateTimeInterface $at = null): ParcelReturn
    {
        return $this->buyerReturn($buyer, ['seller_decision' => ParcelReturn::REJECTED, 'escalated_at' => now()->subDays(3),
            'dispute_outcome' => ParcelReturn::DISPUTE_UPHELD, 'dispute_resolved_at' => $at ?? now()->subDay()]);
    }

    private function staff(string $role = 'admin'): Admin
    {
        return Admin::factory()->create(['role' => $role]);
    }

    // ── 1–3. Thresholds, exclusions, duplicates ─────────────────────────

    public function test_five_buyer_cancelled_orders_in_thirty_days_raise_one_alert_and_four_do_not(): void
    {
        $buyer = Buyer::factory()->create();
        $this->buyerCancellations($buyer, 4);
        $this->assertSame(0, $this->scan()['created']);

        $this->buyerCancellations($buyer, 1);
        $this->assertSame(1, $this->scan()['created']);
        $review = BuyerConductReview::query()->sole();
        $this->assertSame('cancellations', $review->category);
        $this->assertSame(5, $review->qualifying_count);
        $this->assertSame('pending', $review->status);
    }

    public function test_seller_system_old_and_multi_slice_cancellations_are_excluded(): void
    {
        $buyer = Buyer::factory()->create();
        // Seller and system cancellations never count.
        foreach (range(1, 3) as $_) {
            $parcel = $this->parcel($buyer, status: OrderStatus::Cancelled);
            $this->cancel($parcel, 'seller', $parcel->seller_id);
            $this->cancel($this->parcel($buyer, status: OrderStatus::Cancelled), 'system', 0);
        }
        // Older than 30 days.
        $this->buyerCancellations($buyer, 3, now()->subDays(31));
        // One checkout cancelled in three Seller slices is one order.
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        foreach (range(1, 3) as $_) {
            $this->cancel($this->parcel($buyer, $order, OrderStatus::Cancelled), 'buyer', $buyer->id);
        }
        $this->buyerCancellations($buyer, 3);

        $this->scan();
        $this->assertSame(0, BuyerConductReview::query()->count(), 'Only 4 distinct recent Buyer-cancelled orders exist.');
        $this->assertSame(4, app(BuyerConductReviewService::class)->qualifyingEvents($buyer->id, 'cancellations')['count']);
    }

    public function test_three_admin_upheld_rejections_raise_a_returns_alert_and_other_outcomes_never_count(): void
    {
        $buyer = Buyer::factory()->create();
        // Seller rejection the Buyer did not dispute: not substantiated by Admin.
        $this->buyerReturn($buyer, ['seller_decision' => ParcelReturn::REJECTED]);
        // Pending dispute.
        $this->buyerReturn($buyer, ['status' => ParcelReturnStatus::InDispute, 'seller_decision' => ParcelReturn::REJECTED, 'escalated_at' => now()]);
        // Overturned in the Buyer's favour.
        $this->buyerReturn($buyer, ['status' => ParcelReturnStatus::Requested, 'seller_decision' => ParcelReturn::REJECTED,
            'dispute_outcome' => ParcelReturn::DISPUTE_OVERTURNED, 'dispute_resolved_at' => now()]);
        // Approved and withdrawn requests.
        $this->buyerReturn($buyer, ['status' => ParcelReturnStatus::Completed, 'seller_decision' => ParcelReturn::APPROVED]);
        $this->buyerReturn($buyer, ['status' => ParcelReturnStatus::Cancelled]);
        // Upheld outside 90 days.
        $this->upheld($buyer, now()->subDays(91));
        $this->upheld($buyer);
        $this->upheld($buyer);

        $this->scan();
        $this->assertSame(0, BuyerConductReview::query()->count());

        $this->upheld($buyer);
        $this->scan();
        $review = BuyerConductReview::query()->sole();
        $this->assertSame('returns', $review->category);
        $this->assertSame(3, $review->qualifying_count);
    }

    public function test_repeated_scans_never_duplicate_and_resolved_cases_need_new_evidence(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $admin = $this->staff();
        $this->buyerCancellations($buyer, 5);
        $this->scan();
        $this->scan();
        $this->assertSame(1, BuyerConductReview::query()->count());
        Notification::assertSentTo($admin, BuyerConductAlert::class);
        Notification::assertSentToTimes($admin, BuyerConductAlert::class, 1);
        // An alert alone never reaches the Buyer.
        Notification::assertNothingSentTo($buyer);

        $review = BuyerConductReview::query()->sole();
        app(BuyerConductReviewService::class)->decide($review, $admin, 'no_action', null);
        $this->travel(1)->minutes();
        $this->scan();
        $this->assertSame(1, BuyerConductReview::query()->count(), 'Already reviewed activity does not reopen a case.');

        $this->buyerCancellations($buyer, 4);
        $this->scan();
        $this->assertSame(1, BuyerConductReview::query()->count(), 'Four new cancellations are below the threshold.');
        $this->buyerCancellations($buyer, 1);
        $this->scan();
        $this->assertSame(2, BuyerConductReview::query()->count());
        $this->assertSame(5, BuyerConductReview::query()->latest('id')->first()->qualifying_count);
    }

    // ── 4. Access ───────────────────────────────────────────────────────

    public function test_only_reviewing_staff_can_open_or_decide_reviews(): void
    {
        $buyer = Buyer::factory()->create();
        $this->buyerCancellations($buyer, 5);
        $this->scan();
        $review = BuyerConductReview::query()->sole();

        $this->get(route('admin.conduct-reviews.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($this->staff('support'), 'admin')->get(route('admin.conduct-reviews.show', $review->id))->assertForbidden();
        $this->actingAs($this->staff('support'), 'admin')->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'advisory'])->assertForbidden();
        $this->assertFalse($this->actingAs($buyer, 'buyer')->get('/admin/conduct-reviews/'.$review->id)->isSuccessful());
        $this->assertNull($review->fresh()->decision);

        foreach (['super_admin', 'admin', 'moderator'] as $role) {
            $this->actingAs($this->staff($role), 'admin')->get(route('admin.conduct-reviews.index'))->assertOk()->assertSee($review->reference());
            $this->actingAs($this->staff($role), 'admin')->get(route('admin.conduct-reviews.show', $review->id))->assertOk()->assertSee('Changed my mind');
        }
    }

    // ── 5. Justification ────────────────────────────────────────────────

    public function test_formal_warning_and_restriction_require_a_justification(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $this->buyerCancellations($buyer, 5);
        $this->scan();
        $review = BuyerConductReview::query()->sole();
        $admin = $this->staff();

        foreach (['formal_warning', 'account_restriction'] as $decision) {
            $this->actingAs($admin, 'admin')->from(route('admin.conduct-reviews.show', $review->id))
                ->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => $decision, 'decision_reason' => ''])
                ->assertSessionHasErrors('decision_reason');
        }
        $this->assertNull($review->fresh()->decision);
        $this->assertSame('approved', $buyer->fresh()->status);
        Notification::assertNothingSentTo($buyer);

        $this->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'advisory'])->assertSessionHasNoErrors();
        $this->assertSame('advisory', $review->fresh()->decision);
    }

    // ── 6–7. Recipients, idempotency, audit ─────────────────────────────

    public function test_advisory_notifies_only_the_buyer_once_and_is_audited(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $other = Buyer::factory()->create();
        $this->buyerCancellations($buyer, 5);
        $this->scan();
        $review = BuyerConductReview::query()->sole();
        $first = $this->staff();
        $second = $this->staff('moderator');

        $this->actingAs($first, 'admin')->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'advisory', 'decision_reason' => 'Please cancel only when needed.'])
            ->assertSessionHas('success');
        $this->actingAs($second, 'admin')->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'formal_warning', 'decision_reason' => 'A second, conflicting decision.'])
            ->assertSessionHas('warning');

        $review->refresh();
        $this->assertSame('advisory', $review->decision);
        $this->assertSame($first->id, $review->decided_by);
        Notification::assertSentToTimes($buyer, ConductReviewDecision::class, 1);
        Notification::assertSentTo($buyer, ConductReviewDecision::class, function (ConductReviewDecision $n) use ($buyer): bool {
            $data = $n->toArray($buyer);

            return $data['title'] === 'Account advisory' && str_contains($data['message'], 'active')
                && str_contains($data['message'], 'CR-') && ! str_contains(strtolower($data['message']), 'fraud');
        });
        Notification::assertNothingSentTo($other);
        $this->assertSame('approved', $buyer->fresh()->status);

        $log = OperationsAuditLog::query()->where('action', 'buyer_conduct_review_decided')->sole();
        $this->assertSame($first->id, (int) $log->actor_id);
        $this->assertSame('Please cancel only when needed.', $log->reason);
        $this->assertSame('advisory', $log->new_values['decision']);
    }

    public function test_no_action_and_closures_do_not_notify_the_buyer(): void
    {
        Notification::fake();
        foreach (['no_action', 'closed_duplicate', 'closed_unsubstantiated'] as $decision) {
            $buyer = Buyer::factory()->create();
            $this->buyerCancellations($buyer, 5);
            $this->scan();
            $review = BuyerConductReview::query()->where('buyer_id', $buyer->id)->sole();
            app(BuyerConductReviewService::class)->decide($review, $this->staff(), $decision, null);
            $this->assertSame($decision, $review->fresh()->decision);
            Notification::assertNothingSentTo($buyer);
        }
    }

    public function test_restriction_reuses_the_existing_suspension_and_leaves_cod_strikes_unchanged(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $this->buyerCancellations($buyer, 5);
        $this->scan();
        $review = BuyerConductReview::query()->sole();
        $strikes = app(CodRiskService::class)->strikeCount($buyer);

        app(BuyerConductReviewService::class)->decide($review, $this->staff(), 'account_restriction', 'Repeated cancellations after an advisory.');

        $this->assertSame('suspended', $buyer->fresh()->status);
        $this->assertSame('suspended', $review->fresh()->account_status_after);
        $this->assertSame(1, AccountActionHistory::query()->where('account_type', 'buyer')->where('account_id', $buyer->id)->where('action', 'suspended')->count());
        $this->assertSame($strikes, app(CodRiskService::class)->strikeCount($buyer));
        Notification::assertSentTo($buyer, AccountStatusChanged::class);
        Notification::assertSentTo($buyer, ConductReviewDecision::class, fn ($n) => str_contains($n->toArray($buyer)['message'], 'suspended'));

        // An already-suspended account cannot be restricted again; nothing is recorded.
        $again = Buyer::factory()->create(['status' => 'suspended']);
        $this->buyerCancellations($again, 5);
        $this->scan();
        $second = BuyerConductReview::query()->where('buyer_id', $again->id)->sole();
        $this->expectException(ValidationException::class);
        try {
            app(BuyerConductReviewService::class)->decide($second, $this->staff(), 'account_restriction', 'Should not apply to a suspended account.');
        } finally {
            $this->assertNull($second->fresh()->decision);
        }
    }

    // ── Seller reports ──────────────────────────────────────────────────

    private function report(Buyer $buyer): BuyerConductReport
    {
        $parcel = $this->parcel($buyer, status: OrderStatus::Completed);
        $report = new BuyerConductReport;
        $report->forceFill(['seller_order_id' => $parcel->id, 'seller_id' => $parcel->seller_id, 'buyer_id' => $buyer->id,
            'reason' => 'abusive_messages', 'description' => 'Repeated abusive chat messages.', 'evidence' => [],
            'status' => 'open', 'unresolved_key' => 1])->save();

        return $report;
    }

    public function test_seller_reports_enter_review_and_the_seller_is_told_each_stage_once_without_penalty_details(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $report = $this->report($buyer);
        $seller = Seller::query()->find($report->seller_id);
        $this->scan();
        $this->scan();
        $review = BuyerConductReview::query()->sole();
        $this->assertSame($report->id, $review->buyer_conduct_report_id);
        $admin = $this->staff('moderator');

        $this->actingAs($admin, 'admin')->post(route('admin.conduct-reviews.start', $review->id))->assertSessionHas('success');
        $this->post(route('admin.conduct-reviews.start', $review->id));
        $this->assertSame('In Admin review', $report->fresh()->sellerStatusLabel());

        $this->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'formal_warning', 'decision_reason' => 'Abusive messages confirmed in chat logs.']);
        $this->post(route('admin.conduct-reviews.decide', $review->id), ['decision' => 'formal_warning', 'decision_reason' => 'Abusive messages confirmed in chat logs.']);

        $sent = Notification::sent($seller, BuyerReportUpdated::class);
        $this->assertSame(['in_review', 'resolved'], $sent->pluck('stage')->all());
        $resolved = $sent->last()->toArray($seller);
        $this->assertStringNotContainsString('warning', strtolower($resolved['message']));
        $this->assertStringContainsString('Closed — reviewed and recorded', $resolved['message']);
        $this->assertSame('closed_reviewed', $report->fresh()->status);
        Notification::assertSentToTimes($buyer, ConductReviewDecision::class, 1);
        Notification::assertNotSentTo($buyer, BuyerReportUpdated::class);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'buyer_report_reviewed')->count());
    }

    public function test_seller_is_told_once_when_a_report_is_submitted_and_the_old_decision_form_is_retired(): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create();
        $parcel = $this->parcel($buyer, status: OrderStatus::Completed);
        $seller = Seller::query()->find($parcel->seller_id);
        $service = app(BuyerConductReportService::class);
        $report = $service->submit($parcel, $seller, 'abusive_messages', 'Repeated abusive chat messages.', []);
        $service->submit($parcel, $seller, 'abusive_messages', 'Repeated abusive chat messages.', []);

        Notification::assertSentToTimes($seller, BuyerReportUpdated::class, 1);
        $this->assertSame(1, BuyerConductReview::query()->where('buyer_conduct_report_id', $report->id)->count());
        Notification::assertNothingSentTo($buyer);

        $this->expectException(ValidationException::class);
        $service->decide($report, $this->staff(), 'closed_no_action', 'Old form must not bypass conduct review.');
    }
}
