<?php

namespace App\Models\Buyer;

use App\Models\Catalog\Product;
use App\Models\Shared\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    public const MAX_BODY_LENGTH = 500;

    public const MAX_PHOTOS = 5;

    public const BUYER_EDIT_WINDOW_DAYS = 30;

    protected $fillable = [
        'buyer_id',
        'product_id',
        'order_item_id',
        'rating',
        'body',
        'buyer_edited_at',
        'seller_reply',
        'seller_replied_at',
        'seller_reply_edited_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'buyer_edited_at' => 'datetime',
            'seller_replied_at' => 'datetime',
            'seller_reply_edited_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** BUY-RATE-005: at most one buyer edit, within 30 days of submission. */
    public function isEditableByBuyer(): bool
    {
        return $this->buyer_edited_at === null
            && $this->created_at !== null
            && $this->created_at->greaterThanOrEqualTo(now()->subDays(self::BUYER_EDIT_WINDOW_DAYS));
    }
}
