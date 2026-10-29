<?php

namespace App\Http\Controllers\Api\Review;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Buyer\Buyer;
use App\Models\Review;
use App\Models\SellerOrder;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reviews — two routes, one resource.
 *
 * ## Why seller_order_id is the route key, not order_id
 *
 * The app's model of an order is one parcel per shop — exactly what a
 * `SellerOrder` is. When the app says "I am submitting a review for order X",
 * the id it holds is the seller order's id: that is what `OrderResource` sends
 * in the `id` field and what the app stores as `Order.id`. Using the parent
 * `Order`'s id here would require the app to know a distinction it deliberately
 * does not make, and a route that accepted either shape would be one where the
 * same review could be reachable at two URLs.
 *
 * ## The upsert is intentional
 *
 * Re-submitting a review for the same purchase replaces it. This matches the
 * app's behaviour ("Edit Your Review" opens the same form) and the repository
 * interface's own spec ("Adds or replaces"). A second POST from the same buyer
 * for the same (seller_order, product) is an edit, not a duplicate, on both
 * sides of the boundary.
 *
 * ## Photo upload
 *
 * Photo paths are accepted as already-stored local file paths from the app
 * (`photo_path` is an optional string). File upload via multipart is a separate
 * endpoint when needed; for now the client stores photos locally and sends the
 * path, and the server records it as-is. This keeps the review submit path as
 * a plain JSON request rather than multipart.
 *
 * When a real photo upload endpoint lands, `photo_path` will be replaced by
 * a server-issued URL and only this controller changes.
 */
class ReviewController extends Controller
{
    /**
     * GET /api/reviews/mine
     *
     * Every review this buyer has submitted, newest first. Scoped through
     * `buyer_id` from the token, so a request cannot ask for another person's
     * reviews and there is no ownership check to forget.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $reviews = Review::query()
            ->where('buyer_id', $buyer->id)
            ->orderByDesc('created_at')
            ->get();

        return ReviewResource::collection($reviews);
    }

    /**
     * PATCH /api/orders/{sellerOrder}/items/{product}/review
     *
     * Create or replace a review for one product on one order. The route model
     * binding resolves `{sellerOrder}` to a `SellerOrder` row and `{product}` to
     * a `Product` row; 404 if either does not exist.
     *
     * ## Ownership
     *
     * The seller order must belong to the signed-in buyer's own order history.
     * We check this *before* writing, so a valid token cannot review someone
     * else's purchase.
     *
     * ## The product must be an item in this order
     *
     * `order_items.seller_order_id = sellerOrder.id` AND
     * `order_items.product_id = product.id`. A product that was never in this
     * order cannot be reviewed against it — a buyer cannot leave a review on a
     * product they bought on a different order by reusing an old order id.
     *
     * ## 200 vs 201
     *
     * `201` for a new review, `200` for an edit. The app treats both the same,
     * but the distinction is the right one: writing a record vs overwriting it.
     */
    public function upsert(Request $request, SellerOrder $sellerOrder, Product $product): JsonResponse
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        // Ownership: this parcel must be the buyer's.
        abort_unless(
            $sellerOrder->order?->buyer_id === $buyer->id,
            403,
            'This order does not belong to your account.',
        );

        // The product must have been in this order. An order_items row is the
        // evidence — without it, the buyer is asking to review something they
        // did not buy in this transaction.
        $isInOrder = $sellerOrder->orderItems()
            ->where('product_id', $product->id)
            ->exists();

        abort_unless(
            $isInOrder,
            422,
            'That product was not part of this order.',
        );

        $data = $request->validate([
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'photo_path' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = Review::findForPurchase($buyer->id, $sellerOrder->id, $product->id);

        if ($existing !== null) {
            // Edit: update and return 200.
            $existing->update([
                'stars' => $data['stars'],
                'comment' => $data['comment'] ?? '',
                'photo_path' => $data['photo_path'] ?? null,
            ]);

            return (new ReviewResource($existing->fresh()))
                ->response()
                ->setStatusCode(200);
        }

        // New review: create and return 201.
        $review = Review::query()->create([
            'buyer_id' => $buyer->id,
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'stars' => $data['stars'],
            'comment' => $data['comment'] ?? '',
            'photo_path' => $data['photo_path'] ?? null,
        ]);

        return (new ReviewResource($review))
            ->response()
            ->setStatusCode(201);
    }
}
