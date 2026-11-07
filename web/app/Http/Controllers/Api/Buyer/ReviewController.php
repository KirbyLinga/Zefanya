<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Buyer\MobileReviewRequest;
use App\Http\Resources\Mobile\ReviewResource;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\ProductReviewService;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    use \App\Http\Middleware\Traits\ApiResponses;

    public function index(MobileReviewRequest $request): JsonResponse
    {
        $reviews = Review::query()->where('buyer_id', $request->user()->getKey())
            ->with(['photos', 'orderItem.sellerOrder'])->latest('id')->get();

        return $this->success('Reviews retrieved.', ReviewResource::collection($reviews));
    }

    public function save(MobileReviewRequest $request, SellerOrder $shipment, Product $product, ProductReviewService $reviews): JsonResponse
    {
        $review = $reviews->saveForShipment($shipment, $product, $request->user(),
            $request->integer('stars'), $request->validated('comment'), $request->file('photos', []));

        return $this->success('Review saved.', new ReviewResource($review), $review->wasRecentlyCreated ? 201 : 200);
    }
}
