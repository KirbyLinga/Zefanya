<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreProductReviewRequest;
use App\Http\Requests\Buyer\UpdateProductReviewRequest;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Services\Buyer\ProductReviewService;
use Illuminate\Http\RedirectResponse;

class ReviewController extends Controller
{
    public function store(StoreProductReviewRequest $request, Product $product, ProductReviewService $reviews): RedirectResponse
    {
        $data = $request->validated();
        $reviews->save(
            $product,
            $request->user('buyer'),
            (int) $data['rating'],
            $data['body'] ?? null,
            isset($data['order_item_id']) ? (int) $data['order_item_id'] : null,
            $request->file('photos', []),
        );

        return back()->with('success', 'Your review has been posted.');
    }

    public function update(UpdateProductReviewRequest $request, Review $review, ProductReviewService $reviews): RedirectResponse
    {
        $data = $request->validated();
        $reviews->update(
            $review,
            $request->user('buyer'),
            (int) $data['rating'],
            $data['body'] ?? null,
            $request->file('photos', []),
            $data['remove_photos'] ?? [],
        );

        return back()->with('success', 'Your review has been updated.');
    }
}
