<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\OrderItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductReviewService
{
    /**
     * Completed order items owned by the buyer that do not have a review yet.
     *
     * @return Collection<int, OrderItem>
     */
    public function reviewableItems(Product $product, Buyer $buyer): Collection
    {
        return $this->completedItems($product, $buyer)
            ->whereDoesntHave('review')
            ->with(['sellerOrder:id,order_id', 'sellerOrder.order:id,reference'])
            ->orderBy('id')
            ->get();
    }

    /** BUY-RATE-002: only a completed purchase is reviewable. */
    public function eligibleItem(Product $product, Buyer $buyer): ?OrderItem
    {
        return $this->reviewableItems($product, $buyer)->first();
    }

    /**
     * Create a review for one completed order item. BUY-RATE-003 prevents a
     * second review for the same item.
     *
     * @param  array<int, UploadedFile>  $photos
     */
    public function save(
        Product $product,
        Buyer $buyer,
        int $rating,
        ?string $body,
        ?int $orderItemId = null,
        array $photos = [],
    ): Review {
        $this->assertPayload($rating, $body, $photos);

        return DB::transaction(function () use ($product, $buyer, $rating, $body, $orderItemId, $photos): Review {
            // Serialize this buyer's submissions; the unique item index also
            // prevents duplicates from concurrent tabs/processes.
            Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();

            $item = $this->resolveItem($product, $buyer, $orderItemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'review' => 'Confirm receipt of a completed purchase before reviewing this product.',
                ]);
            }

            if ($item->review()->exists()) {
                throw ValidationException::withMessages([
                    'order_item_id' => 'This purchased item has already been reviewed.',
                ]);
            }

            $review = Review::query()->create([
                'buyer_id' => $buyer->id,
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'rating' => $rating,
                'body' => $body,
            ]);

            $this->attachPhotos($review, $photos);

            return $review->load('photos');
        });
    }

    /**
     * BUY-RATE-005: the buyer may edit once, within 30 days of submission.
     * Seller replies are untouched by buyer edits.
     *
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, int|string>  $removePhotoIds
     */
    public function update(
        Review $review,
        Buyer $buyer,
        int $rating,
        ?string $body,
        array $photos = [],
        array $removePhotoIds = [],
    ): Review {
        $this->assertPayload($rating, $body, $photos);

        return DB::transaction(function () use ($review, $buyer, $rating, $body, $photos, $removePhotoIds): Review {
            Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();

            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->buyer_id !== $buyer->id) {
                throw new AuthorizationException('This review does not belong to you.');
            }
            if (! $review->isEditableByBuyer()) {
                throw ValidationException::withMessages([
                    'review' => 'A review can be edited only once and within 30 days of submission.',
                ]);
            }

            $existing = $review->photos()->get();
            $removeIds = collect($removePhotoIds)->map(fn ($id): int => (int) $id);
            $removed = $existing->filter(fn ($photo): bool => $removeIds->contains($photo->id));

            if ($existing->count() - $removed->count() + count($photos) > Review::MAX_PHOTOS) {
                throw ValidationException::withMessages([
                    'photos' => 'A review may contain at most '.Review::MAX_PHOTOS.' photos.',
                ]);
            }

            foreach ($removed as $photo) {
                Storage::disk('public')->delete($photo->path);
                $photo->delete();
            }

            $review->fill([
                'rating' => $rating,
                'body' => $body,
                'buyer_edited_at' => now(),
            ])->save();

            $this->attachPhotos($review, $photos, ((int) $review->photos()->max('sort_order')) + 1);

            return $review->load('photos');
        });
    }

    /** SEL-REV-002: one reply entity per review. */
    public function reply(Review $review, Seller $seller, string $reply): Review
    {
        $reply = trim($reply);
        $this->assertReply($reply);

        return DB::transaction(function () use ($review, $seller, $reply): Review {
            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->assertSellerOwns($review, $seller);

            if ($review->seller_reply !== null) {
                throw ValidationException::withMessages([
                    'reply' => 'You have already replied to this review. Edit your existing reply instead.',
                ]);
            }

            $review->forceFill([
                'seller_reply' => $reply,
                'seller_replied_at' => now(),
                'seller_reply_edited_at' => null,
            ])->save();

            return $review;
        });
    }

    /** SEL-REV-003: the seller may edit the reply but cannot add a second one. */
    public function updateReply(Review $review, Seller $seller, string $reply): Review
    {
        $reply = trim($reply);
        $this->assertReply($reply);

        return DB::transaction(function () use ($review, $seller, $reply): Review {
            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->assertSellerOwns($review, $seller);

            if ($review->seller_reply === null) {
                throw ValidationException::withMessages([
                    'reply' => 'Submit a reply before editing it.',
                ]);
            }

            $review->forceFill([
                'seller_reply' => $reply,
                'seller_reply_edited_at' => now(),
            ])->save();

            return $review;
        });
    }

    private function completedItems(Product $product, Buyer $buyer): Builder
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('sellerOrder', fn (Builder $query): Builder => $query
                ->where('status', OrderStatus::Completed->value)
                ->whereHas('order', fn (Builder $query): Builder => $query->where('buyer_id', $buyer->id)));
    }

    private function resolveItem(Product $product, Buyer $buyer, ?int $orderItemId): ?OrderItem
    {
        if ($orderItemId === null) {
            return $this->eligibleItem($product, $buyer);
        }

        $item = $this->completedItems($product, $buyer)
            ->whereKey($orderItemId)
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([
                'order_item_id' => 'Only a completed purchase of this product can be reviewed.',
            ]);
        }

        return $item;
    }

    /** @param array<int, UploadedFile> $photos */
    private function attachPhotos(Review $review, array $photos, int $startOrder = 0): void
    {
        foreach (array_values($photos) as $index => $photo) {
            $path = $photo->store('reviews/'.$review->id, 'public');
            $review->photos()->create([
                'path' => $path,
                'sort_order' => $startOrder + $index,
            ]);
        }
    }

    /** @param array<int, UploadedFile> $photos */
    private function assertPayload(int $rating, ?string $body, array $photos): void
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => 'The rating must be between 1 and 5.',
            ]);
        }

        if ($body !== null && mb_strlen($body) > Review::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'The review may not be longer than '.Review::MAX_BODY_LENGTH.' characters.',
            ]);
        }

        if (count($photos) > Review::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'A review may contain at most '.Review::MAX_PHOTOS.' photos.',
            ]);
        }
    }

    private function assertReply(string $reply): void
    {
        $reply = trim($reply);

        if ($reply === '' || mb_strlen($reply) > 1000) {
            throw ValidationException::withMessages([
                'reply' => 'Enter a reply between 1 and 1,000 characters.',
            ]);
        }
    }

    private function assertSellerOwns(Review $review, Seller $seller): void
    {
        $owned = Product::withTrashed()
            ->whereKey($review->product_id)
            ->where('seller_id', $seller->id)
            ->exists();

        if (! $owned) {
            throw new AuthorizationException('This review is not for one of your products.');
        }
    }
}
