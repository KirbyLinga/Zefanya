<?php

namespace App\Services\Admin;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\BuyerConductReport;
use App\Models\Shared\BuyerConductReview;
use App\Models\Shared\StatusHistory;
use App\Notifications\Admin\AccountStatusChanged;
use App\Notifications\Admin\BuyerConductAlert;
use App\Notifications\Buyer\ConductReviewDecision;
use App\Notifications\Seller\BuyerReportUpdated;
use App\Services\Buyer\BuyerCancellationHistory;
use App\Services\Shared\AccountSessionService;
use App\Support\RuntimeSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin-controlled Buyer conduct review.
 *
 * Alerts are raised for human review only. Nothing here warns, restricts or
 * notifies a Buyer until an Admin records a decision, and the only restriction
 * available is the existing account suspension (AccountStatusService). COD
 * strikes, cancellation rules, return eligibility and money are not touched.
 */
class BuyerConductReviewService
{
    public const CANCELLATION_THRESHOLD = 5;

    public const CANCELLATION_WINDOW_DAYS = 30;

    public const RETURN_THRESHOLD = 3;

    public const RETURN_WINDOW_DAYS = 90;

    public const STAFF_ROLES = ['super_admin', 'admin', 'moderator'];

    public function __construct(
        private readonly BuyerCancellationHistory $cancellations,
        private readonly AccountStatusService $accountStatuses,
        private readonly AccountSessionService $accountSessions,
    ) {}

    // ── Alert scan ────────────────────────────────────────────────────────

    /**
     * Raise or refresh review alerts. Idempotent: repeated scans never create a
     * second open alert for the same Buyer and category.
     *
     * @return array{created: int, refreshed: int}
     */
    public function scan(): array
    {
        $result = ['created' => 0, 'refreshed' => 0];
        if (! BuyerConductReview::available()) {
            return $result;
        }

        foreach ($this->cancellationCandidates() as $buyerId) {
            $this->tally($result, $this->syncActivity((int) $buyerId, BuyerConductReview::CATEGORY_CANCELLATIONS));
        }
        foreach ($this->returnCandidates() as $buyerId) {
            $this->tally($result, $this->syncActivity((int) $buyerId, BuyerConductReview::CATEGORY_RETURNS));
        }
        BuyerConductReport::query()->where('unresolved_key', 1)->whereDoesntHave('review')->get()
            ->each(function (BuyerConductReport $report) use (&$result): void {
                $this->tally($result, $this->syncReport($report) === null ? null : 'created');
            });

        return $result;
    }

    /** The review case for a Seller report, created on first need. */
    public function syncReport(BuyerConductReport $report): ?BuyerConductReview
    {
        if (! BuyerConductReview::available()) {
            return null;
        }
        $existing = BuyerConductReview::query()->where('buyer_conduct_report_id', $report->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        // Reports already decided before conduct review existed keep their own decision.
        if ((int) $report->unresolved_key !== 1) {
            return null;
        }

        try {
            $review = new BuyerConductReview;
            $review->forceFill([
                'buyer_id' => $report->buyer_id,
                'category' => BuyerConductReview::CATEGORY_SELLER_REPORT,
                'buyer_conduct_report_id' => $report->id,
                'window_started_at' => $report->created_at,
                'window_ended_at' => $report->created_at,
                'qualifying_count' => 1,
                'evidence' => ['report_ids' => [$report->id]],
                'status' => 'pending',
                'unresolved_key' => null,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return BuyerConductReview::query()->where('buyer_conduct_report_id', $report->id)->first();
        }

        return $review;
    }

    /** 'created', 'refreshed' or null when the Buyer does not qualify. */
    private function syncActivity(int $buyerId, string $category): ?string
    {
        $events = $this->qualifyingEvents($buyerId, $category);
        $threshold = $category === BuyerConductReview::CATEGORY_CANCELLATIONS ? self::CANCELLATION_THRESHOLD : self::RETURN_THRESHOLD;
        $open = BuyerConductReview::query()->where('buyer_id', $buyerId)->where('category', $category)->where('unresolved_key', 1)->first();

        if ($open !== null) {
            // Keep the open case's evidence current; never notify anyone for a refresh.
            if ($events['count'] > 0 && $events['count'] !== (int) $open->qualifying_count) {
                $open->forceFill([
                    'qualifying_count' => $events['count'], 'evidence' => $events['evidence'],
                    'window_started_at' => $events['first'], 'window_ended_at' => $events['last'],
                ])->save();

                return 'refreshed';
            }

            return null;
        }
        if ($events['count'] < $threshold) {
            return null;
        }

        try {
            $review = new BuyerConductReview;
            $review->forceFill([
                'buyer_id' => $buyerId, 'category' => $category,
                'window_started_at' => $events['first'], 'window_ended_at' => $events['last'],
                'qualifying_count' => $events['count'], 'evidence' => $events['evidence'],
                'status' => 'pending', 'unresolved_key' => 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return null; // A concurrent scan raised it first.
        }

        $this->notifyStaff($review);

        return 'created';
    }

    /**
     * Qualifying activity inside the rolling window, counted only after the
     * Buyer's latest resolved case in this category, so a resolved case is never
     * re-raised from the evidence it already covered.
     *
     * @return array{count: int, first: ?Carbon, last: ?Carbon, evidence: array<string, mixed>}
     */
    public function qualifyingEvents(int $buyerId, string $category): array
    {
        $days = $category === BuyerConductReview::CATEGORY_CANCELLATIONS ? self::CANCELLATION_WINDOW_DAYS : self::RETURN_WINDOW_DAYS;
        $since = now()->subDays($days);
        $covered = BuyerConductReview::available()
            ? BuyerConductReview::query()->where('buyer_id', $buyerId)->where('category', $category)
                ->where('status', 'resolved')->max('window_ended_at')
            : null;
        if ($covered !== null && Carbon::parse($covered)->greaterThan($since)) {
            $since = Carbon::parse($covered);
            $strict = true;
        }

        if ($category === BuyerConductReview::CATEGORY_CANCELLATIONS) {
            // Buyer-initiated only (typed actor); Seller and system cancellations never count.
            $rows = $this->cancellations->query($buyerId)
                ->where('status_histories.created_at', isset($strict) ? '>' : '>=', $since)
                ->join('seller_orders', 'seller_orders.id', '=', 'status_histories.seller_order_id')
                ->orderBy('status_histories.created_at')
                ->get(['status_histories.id', 'status_histories.created_at', 'seller_orders.order_id']);
            // A multi-Seller checkout cancelled in several slices is one order.
            $orders = $rows->unique('order_id');

            return [
                'count' => $orders->count(),
                'first' => $orders->first()?->created_at,
                'last' => $rows->last()?->created_at,
                'evidence' => ['status_history_ids' => $rows->pluck('id')->all(), 'order_ids' => $orders->pluck('order_id')->values()->all()],
            ];
        }

        $rows = $this->upheldReturns()
            ->where('orders.buyer_id', $buyerId)
            ->where('parcel_returns.dispute_resolved_at', isset($strict) ? '>' : '>=', $since)
            ->orderBy('parcel_returns.dispute_resolved_at')
            ->get(['parcel_returns.id', 'parcel_returns.dispute_resolved_at']);

        return [
            'count' => $rows->count(),
            'first' => $rows->first() ? Carbon::parse($rows->first()->dispute_resolved_at) : null,
            'last' => $rows->last() ? Carbon::parse($rows->last()->dispute_resolved_at) : null,
            'evidence' => ['parcel_return_ids' => $rows->pluck('id')->all()],
        ];
    }

    /**
     * Final, substantiated, Buyer-unfavorable return outcomes: the Buyer's own
     * request, rejected by the Seller, disputed by the Buyer, and the rejection
     * then upheld by Admin. A Seller rejection on its own, pending disputes,
     * overturned rejections, withdrawn requests, approved returns and receipt
     * disputes (closed without a structured finding) never count.
     */
    private function upheldReturns()
    {
        return ParcelReturn::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'parcel_returns.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->whereNotNull('parcel_returns.seller_response_due_at')
            ->where('parcel_returns.seller_decision', ParcelReturn::REJECTED)
            ->where('parcel_returns.dispute_outcome', ParcelReturn::DISPUTE_UPHELD)
            ->whereNotNull('parcel_returns.dispute_resolved_at');
    }

    /** @return Collection<int, int> */
    private function cancellationCandidates(): Collection
    {
        if (! RuntimeSchema::hasColumn('status_histories', 'actor_type')) {
            return collect();
        }

        return StatusHistory::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'status_histories.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('status_histories.to_status', OrderStatus::Cancelled)
            ->where('status_histories.actor_type', OrderActor::Buyer->value)
            ->whereColumn('status_histories.actor_id', 'orders.buyer_id')
            ->where('status_histories.created_at', '>=', now()->subDays(self::CANCELLATION_WINDOW_DAYS))
            ->groupBy('orders.buyer_id')
            ->havingRaw('COUNT(DISTINCT seller_orders.order_id) >= ?', [self::CANCELLATION_THRESHOLD])
            ->pluck('orders.buyer_id')
            ->merge($this->openBuyerIds(BuyerConductReview::CATEGORY_CANCELLATIONS))
            ->unique()->values();
    }

    /** @return Collection<int, int> */
    private function returnCandidates(): Collection
    {
        return $this->upheldReturns()
            ->where('parcel_returns.dispute_resolved_at', '>=', now()->subDays(self::RETURN_WINDOW_DAYS))
            ->groupBy('orders.buyer_id')
            ->havingRaw('COUNT(*) >= ?', [self::RETURN_THRESHOLD])
            ->pluck('orders.buyer_id')
            ->merge($this->openBuyerIds(BuyerConductReview::CATEGORY_RETURNS))
            ->unique()->values();
    }

    /** Buyers with an open case, so their evidence stays current even below the threshold. */
    private function openBuyerIds(string $category): Collection
    {
        return BuyerConductReview::query()->where('category', $category)->where('unresolved_key', 1)->pluck('buyer_id');
    }

    private function tally(array &$result, ?string $outcome): void
    {
        if ($outcome !== null) {
            $result[$outcome]++;
        }
    }

    // ── Admin actions ─────────────────────────────────────────────────────

    /** Mark a pending case as in review. The reporting Seller is told once. */
    public function startReview(BuyerConductReview $review, Admin $admin): void
    {
        $this->authorizeStaff($admin);
        $started = DB::transaction(function () use ($review, $admin): bool {
            $locked = BuyerConductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return false;
            }
            $locked->forceFill(['status' => 'in_review', 'review_started_at' => now(), 'review_started_by' => $admin->id])->save();
            OperationsAuditLog::record($admin, 'buyer_conduct_review_started', $locked, 'Conduct review '.$locked->reference(),
                ['status' => 'pending'], ['status' => 'in_review']);

            return true;
        });

        if ($started) {
            $review->refresh();
            $this->notifySeller($review, 'seller_review_notified_at', 'in_review');
        }
    }

    /**
     * Record a final decision. A resolved case is immutable: a replay or a
     * second Admin's decision returns false and sends nothing.
     */
    public function decide(BuyerConductReview $review, Admin $admin, string $decision, ?string $reason): bool
    {
        $this->authorizeStaff($admin);
        $reason = trim((string) $reason);
        if (! array_key_exists($decision, BuyerConductReview::DECISIONS)) {
            throw ValidationException::withMessages(['decision' => 'Choose a decision.']);
        }
        if (in_array($decision, BuyerConductReview::JUSTIFIED_DECISIONS, true) && mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['decision_reason' => 'A formal warning or account restriction needs a justification of at least 10 characters.']);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['decision_reason' => 'Keep the reason within 2000 characters.']);
        }

        $decided = DB::transaction(function () use ($review, $admin, $decision, $reason): bool {
            $locked = BuyerConductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->isResolved()) {
                return false;
            }
            $buyer = Buyer::query()->whereKey($locked->buyer_id)->first();
            $before = $buyer?->status instanceof \BackedEnum ? $buyer->status->value : $buyer?->status;
            $after = $before;

            if ($decision === 'account_restriction') {
                // Reuse the existing suspension path (status change + account action history).
                if ($buyer === null || ! $this->accountStatuses->transition($buyer, $admin, 'suspended', 'Conduct review '.$locked->reference().': '.$reason)) {
                    throw ValidationException::withMessages(['decision' => 'Only an active (approved) Buyer account can be restricted. Choose another decision.']);
                }
                $after = 'suspended';
            }

            $old = ['status' => $locked->status];
            $locked->forceFill([
                'status' => 'resolved', 'unresolved_key' => null, 'decision' => $decision,
                'decision_reason' => $reason !== '' ? $reason : null,
                'decided_by' => $admin->id, 'decided_at' => now(),
                'account_status_before' => $before, 'account_status_after' => $after,
            ])->save();
            OperationsAuditLog::record($admin, 'buyer_conduct_review_decided', $locked, 'Conduct review '.$locked->reference(),
                $old + ['account' => $before], ['status' => 'resolved', 'decision' => $decision, 'account' => $after], $reason !== '' ? $reason : null);

            if ($locked->buyer_conduct_report_id !== null) {
                $report = BuyerConductReport::query()->whereKey($locked->buyer_conduct_report_id)->lockForUpdate()->first();
                if ($report !== null && $report->status === 'open') {
                    $status = BuyerConductReview::reportStatusFor($decision);
                    $report->forceFill(['status' => $status, 'unresolved_key' => null, 'reviewed_by' => $admin->id,
                        'reviewed_at' => now(), 'decision_note' => 'Resolved through conduct review '.$locked->reference().'.'])->save();
                    OperationsAuditLog::record($admin, 'buyer_report_reviewed', $report, 'Buyer report #'.$report->id,
                        ['status' => 'open'], ['status' => $status], 'Conduct review '.$locked->reference());
                }
            }

            return true;
        });

        if (! $decided) {
            return false;
        }

        // Everything below runs only after the decision is committed.
        $review->refresh();
        if ($decision === 'account_restriction') {
            $this->endBuyerSessions($review);
        }
        if (in_array($decision, BuyerConductReview::BUYER_NOTIFIED_DECISIONS, true)) {
            $this->once($review, 'buyer_notified_at', fn () => $review->buyer?->notify(new ConductReviewDecision($review)));
        }
        $this->notifySeller($review, 'seller_resolved_notified_at', 'resolved');

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function authorizeStaff(Admin $admin): void
    {
        abort_unless(in_array($admin->role, self::STAFF_ROLES, true), 403);
        abort_unless(BuyerConductReview::available(), 503, 'Conduct review is awaiting the database update.');
    }

    /** Same session and mail steps as the existing Admin suspend action. */
    private function endBuyerSessions(BuyerConductReview $review): void
    {
        $buyer = $review->buyer;
        if ($buyer === null) {
            return;
        }
        try {
            if (config('session.driver') === 'database' && filled(config('session.table'))) {
                $this->accountSessions->revoke('buyer', (int) $buyer->id);
            }
            if ($buyer->remember_token !== null) {
                $buyer->setRememberToken(null);
                $buyer->saveQuietly();
            }
        } catch (Throwable $e) {
            report($e);
        }
        $this->once($review, null, fn () => $buyer->notify(new AccountStatusChanged('suspended')));
    }

    private function notifySeller(BuyerConductReview $review, string $column, string $stage): void
    {
        if ($review->buyer_conduct_report_id === null) {
            return;
        }
        $review->loadMissing('report.seller', 'report.sellerOrder.order');
        $report = $review->report;
        if ($report?->seller === null) {
            return;
        }
        $this->once($review, $column, fn () => $report->seller->notify(new BuyerReportUpdated($report, $stage)));
    }

    /**
     * Claim a notification column atomically, then send. A retry or a second
     * request finds the column set and sends nothing. A failed send releases
     * the claim and is reported, without undoing the saved decision.
     */
    private function once(BuyerConductReview $review, ?string $column, callable $send): void
    {
        if ($column !== null) {
            $claimed = BuyerConductReview::query()->whereKey($review->id)->whereNull($column)->update([$column => now()]);
            if ($claimed !== 1) {
                return;
            }
        }
        try {
            $send();
        } catch (Throwable $e) {
            if ($column !== null) {
                BuyerConductReview::query()->whereKey($review->id)->update([$column => null]);
            }
            report($e);
        }
    }

    private function notifyStaff(BuyerConductReview $review): void
    {
        try {
            Admin::query()->whereIn('role', self::STAFF_ROLES)->each(fn (Admin $admin) => $admin->notify(new BuyerConductAlert($review)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── History for the Admin detail page ─────────────────────────────────

    /** @return array<string, mixed> */
    public function history(BuyerConductReview $review): array
    {
        $buyerId = (int) $review->buyer_id;

        return [
            'cancellations' => $this->cancellations->query($buyerId)->with('sellerOrder.order')
                ->where('status_histories.created_at', '>=', now()->subDays(self::RETURN_WINDOW_DAYS))
                ->latest('status_histories.id')->limit(50)->get(),
            'cancellationOrders' => $this->cancellations->orderCount($buyerId),
            'returns' => ParcelReturn::query()->with('sellerOrder.order')
                ->whereNotNull('seller_response_due_at')
                ->whereHas('sellerOrder.order', fn ($q) => $q->where('buyer_id', $buyerId))
                ->latest('id')->limit(50)->get(),
            'reports' => BuyerConductReport::query()->with(['seller', 'sellerOrder.order'])->where('buyer_id', $buyerId)->latest('id')->limit(50)->get(),
            'reviews' => BuyerConductReview::query()->with('decider')->where('buyer_id', $buyerId)->whereKeyNot($review->id)->latest('id')->limit(50)->get(),
            'audit' => OperationsAuditLog::query()->where('entity_type', 'buyer_conduct_review')->where('entity_id', $review->id)->latest('id')->get(),
        ];
    }
}
