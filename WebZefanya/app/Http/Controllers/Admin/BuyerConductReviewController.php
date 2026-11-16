<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shared\BuyerConductReview;
use App\Services\Admin\BuyerConductReviewService;
use App\Services\Buyer\CodRiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class BuyerConductReviewController extends Controller
{
    public function index(Request $request, BuyerConductReviewService $service)
    {
        abort_unless(BuyerConductReview::available(), 503, 'Conduct review is awaiting the database update.');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'category' => ['nullable', Rule::in(array_keys(BuyerConductReview::CATEGORIES))],
        ]);
        $status = $data['status'] ?? 'open';
        $category = $data['category'] ?? null;

        // Raising alerts is idempotent; a scan failure must not hide existing cases.
        try {
            $service->scan();
        } catch (Throwable $e) {
            report($e);
        }

        $reviews = BuyerConductReview::query()->with(['buyer', 'decider'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['pending', 'in_review']))
            ->when($status === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->when($category !== null, fn ($q) => $q->where('category', $category))
            ->latest('id')->paginate(20)->withQueryString();

        return response()->view('Admin.ConductReviews.index', compact('reviews', 'status', 'category'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $review, BuyerConductReviewService $service, CodRiskService $risk)
    {
        $review = $this->find($review);
        $review->load(['buyer', 'decider', 'report.seller', 'report.sellerOrder.order']);
        $history = $service->history($review);
        $codStrikes = $review->buyer !== null && CodRiskService::available() ? $risk->strikeCount($review->buyer) : null;

        return response()->view('Admin.ConductReviews.show', compact('review', 'history', 'codStrikes') + [
            'codStrikeLimit' => CodRiskService::STRIKE_LIMIT,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, int $review, BuyerConductReviewService $service): RedirectResponse
    {
        $review = $this->find($review);
        $service->startReview($review, $request->user('admin'));

        return back()->with('success', 'Review started. The Buyer has not been notified.');
    }

    public function decide(Request $request, int $review, BuyerConductReviewService $service): RedirectResponse
    {
        $review = $this->find($review);
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(BuyerConductReview::DECISIONS))],
            'decision_reason' => [
                Rule::requiredIf(in_array($request->input('decision'), BuyerConductReview::JUSTIFIED_DECISIONS, true)),
                'nullable', 'string', 'min:10', 'max:2000',
            ],
        ], [
            'decision_reason.required' => 'A formal warning or account restriction needs a written justification.',
        ]);

        if (! $service->decide($review, $request->user('admin'), $data['decision'], $data['decision_reason'] ?? null)) {
            return back()->with('warning', 'This review was already resolved. The earlier decision was kept and nothing was sent again.');
        }

        $notified = in_array($data['decision'], BuyerConductReview::BUYER_NOTIFIED_DECISIONS, true);

        return back()->with('success', 'Decision recorded.'.($notified ? ' The Buyer has been notified.' : ' The Buyer was not notified.'));
    }

    /** Resolved after the schema check, so a missing table gives a 503 rather than a query error. */
    private function find(int $id): BuyerConductReview
    {
        abort_unless(BuyerConductReview::available(), 503, 'Conduct review is awaiting the database update.');

        return BuyerConductReview::query()->findOrFail($id);
    }
}
