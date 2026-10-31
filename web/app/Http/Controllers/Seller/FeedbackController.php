<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\SellerReplyRequest;
use App\Models\Buyer\Review;
use App\Services\Buyer\ProductReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function index(Request $request): View
    {
        $seller = $request->user('seller');

        $reviews = Review::query()
            ->whereHas('product', fn ($query) => $query->where('seller_id', $seller->id))
            ->with([
                'buyer:id,first_name,last_name',
                'product:id,name,slug',
                'orderItem:id,product_name',
                'photos',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('Seller.Feedback.index', compact('reviews'));
    }

    public function reply(SellerReplyRequest $request, Review $review, ProductReviewService $reviews): RedirectResponse
    {
        $reviews->reply($review, $request->user('seller'), $request->validated()['reply']);

        return back()->with('success', 'Your reply has been posted.');
    }

    public function updateReply(SellerReplyRequest $request, Review $review, ProductReviewService $reviews): RedirectResponse
    {
        $reviews->updateReply($review, $request->user('seller'), $request->validated()['reply']);

        return back()->with('success', 'Your reply has been updated.');
    }
}
