<?php

namespace App\Models\Shared;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'buyer_id',
        'seller_id',
        'product_id',
        'order_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * The single thread between a buyer and a seller (unique per pair). When
     * opened from a product or order, the thread's context moves to it.
     */
    public static function open(int $buyerId, int $sellerId, ?int $productId = null, ?int $orderId = null): self
    {
        $conversation = self::firstOrCreate(['buyer_id' => $buyerId, 'seller_id' => $sellerId]);

        if ($productId !== null || $orderId !== null) {
            $conversation->update(['product_id' => $productId, 'order_id' => $orderId]);
        }

        return $conversation;
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }
}
