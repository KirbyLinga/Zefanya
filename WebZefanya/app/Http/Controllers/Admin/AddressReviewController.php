<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Shared\OrderAddressReview;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\OrderAddressReviewService;
use App\Services\Buyer\CodRiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddressReviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(OrderAddressReview::available(), 503, 'Address review is awaiting the database update.');
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'pending', 'not_confirmed', 'confirmed', 'all'])]]);
        $status = $data['status'] ?? 'open';

        $reviews = OrderAddressReview::query()->with(['buyer', 'seller', 'sellerOrder.order', 'decider'])
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'confirmed'))
            ->when(in_array($status, ['pending', 'not_confirmed', 'confirmed'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return response()->view('Admin.AddressReviews.index', compact('reviews', 'status'))->header('Cache-Control', 'no-store, private');
    }

    public function show(int $review, CodRiskService $risk)
    {
        $review = $this->find($review);
        $review->load(['buyer', 'seller', 'sellerOrder.order', 'sellerOrder.orderItems', 'decider']);
        $buyer = $review->buyer;
        $history = [
            'delivered' => $buyer ? SellerOrder::query()->whereHas('order', fn ($q) => $q->where('buyer_id', $buyer->id))
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])->count() : 0,
            'codStrikes' => $buyer && CodRiskService::available() ? $risk->strikeCount($buyer) : null,
            'codStrikeLimit' => CodRiskService::STRIKE_LIMIT,
            'otherReviews' => OrderAddressReview::query()->where('buyer_id', $review->buyer_id)->whereKeyNot($review->id)->latest('id')->limit(20)->get(),
            'audit' => OperationsAuditLog::query()->where('entity_type', 'order_address_review')->where('entity_id', $review->id)->latest('id')->get(),
        ];

        return response()->view('Admin.AddressReviews.show', compact('review', 'history'))->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, int $review, OrderAddressReviewService $service): RedirectResponse
    {
        $review = $this->find($review);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirmed', 'not_confirmed'])],
            'call_outcome' => ['required', Rule::in(array_keys(OrderAddressReview::CALL_OUTCOMES))],
            'decision_note' => [Rule::requiredIf($request->input('decision') === 'not_confirmed'), 'nullable', 'string', 'min:10', 'max:1000'],
        ], [
            'decision_note.required' => 'Explain why the address could not be confirmed.',
        ]);

        if (! $service->decide($review, $request->user('admin'), $data['decision'], $data['call_outcome'], $data['decision_note'] ?? null)) {
            return back()->with('warning', 'Nothing changed: this address check already has that result, or it was already confirmed.');
        }

        return back()->with('success', $data['decision'] === 'confirmed'
            ? 'Address confirmed. The shipment is released for pickup, and the Buyer and Seller were notified.'
            : 'Recorded as not confirmed. The shipment stays on hold; the Seller may decline it. The Buyer and Seller were notified.');
    }

    private function find(int $id): OrderAddressReview
    {
        abort_unless(OrderAddressReview::available(), 503, 'Address review is awaiting the database update.');

        return OrderAddressReview::query()->findOrFail($id);
    }
}
