<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One review, in the shape the Flutter app's `ProductReview` wants.
 *
 * ## The key fields
 *
 * `product_id` is a string for the same reason it is everywhere else the app reads
 * it: the mock catalogue used string literals (`'p1'`), so every live id must also
 * arrive as a string or set-membership tests need to know which mode they are in.
 *
 * `order_id` is the *seller order's* id, cast to string. The app's concept of an
 * order is one parcel per shop (`SellerOrder`), so a seller order id is the exact
 * thing the app passes back when it submits a review. The parent `Order`'s id is
 * carried for completeness but is not part of the uniqueness key.
 *
 * `photo_url` is absent when no photo was attached, and an already-absolute URL
 * passes through untouched (same rule as `ProductResource::photoUrl()`).
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            // The seller order id is what the app calls its "order id" — one
            // seller_order = one Order in the app's model.
            'order_id' => (string) $this->seller_order_id,
            // Null when the product was removed after the review was written.
            'product_id' => $this->product_id !== null ? (string) $this->product_id : null,
            'stars' => (int) $this->stars,
            'comment' => (string) ($this->comment ?? ''),
            'photo_url' => $this->photoUrls()[0] ?? null,
            'photo_urls' => $this->photoUrls(),
            'verified_purchase' => (bool) $this->verified_purchase,
            // Masked on the server so a future public review list cannot leak
            // the buyer's full name or email by rendering the row directly.
            'reviewer_name' => $this->buyer?->maskedName() ?: 'Verified buyer',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Server-stored photo paths, resolved to public URLs. `photo_path` is the
     * legacy single-photo column; it is read only when the new list is empty.
     *
     * @return list<string>
     */
    private function photoUrls(): array
    {
        $paths = $this->photo_paths ?? [];

        if ($paths === [] && $this->photo_path !== null) {
            $paths = [$this->photo_path];
        }

        $urls = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $urls[] = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return $urls;
    }
}
