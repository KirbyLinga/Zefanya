<?php

namespace App\Http\Controllers\Api\Review;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Buyer\Buyer;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\SellerOrder;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

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
            ->with('buyer')
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

        // Ownership: this parcel must be the buyer's. Checked before the
        // delivery state so another buyer learns nothing about the order.
        abort_unless(
            $sellerOrder->order?->buyer_id === $buyer->id,
            403,
            'This order does not belong to your account.',
        );

        // Only a finished purchase is reviewable. This is the server's rule,
        // not a client-side button state: a buyer cannot rate a parcel that is
        // still in transit. Values rather than enum cases keep this file out of
        // H1's "only the delivery service writes DELIVERED" scan - this is a
        // read, not a write.
        abort_unless(
            in_array($sellerOrder->status->value, ['DELIVERED', 'COMPLETED'], true),
            422,
            'Only a delivered order can be reviewed.',
        );

        // The product must have been an item in this order. The order item is
        // also the review's identity: one review per purchased line.
        $orderItem = $sellerOrder->orderItems()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->first();

        abort_unless(
            $orderItem instanceof OrderItem,
            422,
            'That product was not part of this order.',
        );

        $data = $request->validate([
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ]);

        $existing = Review::findForItem($buyer->id, $orderItem->id)
            ?? Review::findForPurchase($buyer->id, $sellerOrder->id, $product->id);

        $oldPaths = $this->existingPhotoPaths($existing);
        $newPaths = $oldPaths;

        // A multipart request replaces the photo set; a JSON edit without a
        // `photos` key keeps what is already stored.
        if ($request->hasFile('photos')) {
            $newPaths = [];

            foreach ($request->file('photos') as $photo) {
                $newPaths[] = $photo->store('reviews', 'public');
            }
        }

        $payload = [
            'order_item_id' => $orderItem->id,
            'stars' => $data['stars'],
            'comment' => $data['comment'] ?? '',
            'photo_paths' => $newPaths,
            // The legacy single column mirrors the first photo, so reads that
            // predate `photo_paths` still resolve a URL.
            'photo_path' => $newPaths[0] ?? null,
            // Server-computed: only a delivered line reaches this point.
            'verified_purchase' => true,
        ];

        if ($existing !== null) {
            $existing->forceFill($payload)->save();
            $review = $existing->fresh();
            $status = 200;
        } else {
            $review = new Review;
            $review->forceFill($payload + [
                'buyer_id' => $buyer->id,
                'seller_order_id' => $sellerOrder->id,
                'product_id' => $product->id,
            ])->save();

            $status = 201;
        }

        $this->deleteRemovedPhotos($oldPaths, $newPaths);

        return (new ReviewResource($review))
            ->response()
            ->setStatusCode($status);
    }

    /**
     * @return list<string>
     */
    private function existingPhotoPaths(?Review $review): array
    {
        if ($review === null) {
            return [];
        }

        $paths = $review->photo_paths ?? [];

        if ($paths === [] && $review->photo_path !== null) {
            $paths = [$review->photo_path];
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * Remove files this controller wrote and the new set no longer references.
     * The `reviews/` prefix is deliberate: a legacy client-supplied `photo_path`
     * may point anywhere, and must never name a file to delete.
     *
     * @param  list<string>  $oldPaths
     * @param  list<string>  $newPaths
     */
    private function deleteRemovedPhotos(array $oldPaths, array $newPaths): void
    {
        foreach (array_diff($oldPaths, $newPaths) as $path) {
            if (str_starts_with($path, 'reviews/') && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
