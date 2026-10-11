<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
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

    public function unreadMessagesFor(string $role, int $userId): int
    {
        return $this->messages()
            ->where('read_at', null)
            ->where(function ($query) use ($role) {
                if ($role === 'buyer') {
                    $query->where('sender_role', 'seller');
                } else {
                    $query->where('sender_role', 'buyer');
                }
            })
            ->count();
    }
}
