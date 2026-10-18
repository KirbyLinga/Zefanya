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
            'photo_url' => $this->photoUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        // Already-absolute URLs (seeded or externally hosted) pass through;
        // uploaded paths are resolved through the public disk.
        return str_starts_with($this->photo_path, 'http://') || str_starts_with($this->photo_path, 'https://')
            ? $this->photo_path
            : Storage::disk('public')->url($this->photo_path);
    }
}
