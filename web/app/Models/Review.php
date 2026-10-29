<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review, written by a buyer for one product on one order.
 *
 * ## Why the key is (buyer_id, seller_order_id, product_id)
 *
 * The app's identity is `(orderId, productId)` — the same buyer can buy the same
 * product twice and rate each purchase separately. On the server, `seller_order_id`
 * is the exact equivalent of the app's `orderId` — a `SellerOrder` is the
 * per-shop fulfilment unit the app models as one `Order`. Using `order_id` here
 * instead would allow two reviews for the same product in one checkout, which
 * the app cannot represent and the unique constraint on the Dart side would
 * already forbid.
 *
 * ## The aggregate (`Product.rating`/`ratingCount`)
 *
 * Not on this model. The catalogue resource computes the live aggregate when it
 * lands (a `whereHas` + `avg`), and `ProductResource` gains the two fields then.
 * This model's job is to store the individual review — the aggregate is a read
 * concern of the catalogue.
 */
class Review extends Model
{
    protected $fillable = [
        'buyer_id',
        'seller_order_id',
        'product_id',
        'stars',
        'comment',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /**
     * Nullable: the product may have been removed since the review was written.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The one review a buyer left for a specific product on a specific order,
     * or null. Used by the controller for upsert routing.
     */
    public static function findForPurchase(int $buyerId, int $sellerOrderId, ?int $productId): ?self
    {
        if ($productId === null) {
            return null;
        }

        return self::query()
            ->where('buyer_id', $buyerId)
            ->where('seller_order_id', $sellerOrderId)
            ->where('product_id', $productId)
            ->first();
    }
}
