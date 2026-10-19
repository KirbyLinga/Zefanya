<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreProductReviewRequest;
use App\Models\Product;
use App\Services\ProductReviewService;
use Illuminate\Http\RedirectResponse;

class ReviewController extends Controller
{
    public function store(StoreProductReviewRequest $request, Product $product, ProductReviewService $reviews): RedirectResponse
    {
        $data = $request->validated();
        $reviews->save($product, $request->user('buyer'), (int) $data['rating'], $data['body'] ?? null);

        return back()->with('success', 'Your review has been saved.');
    }
}
