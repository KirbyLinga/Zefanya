<?php

namespace App\Models\Seller;

use App\Enums\PromotionKind;
use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SellerPromotion extends Model
{
    protected $fillable = [
        'seller_id',
        'kind',
        'name',
        'percent',
        'buy_quantity',
        'get_quantity',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PromotionKind::class,
            'percent' => 'integer',
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'seller_promotion_product');
    }

    public function scopeOngoing(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function label(): string
    {
        if ($this->kind === PromotionKind::BundlePercent) {
            return "{$this->percent}% off the bundle";
        }

        return "Buy {$this->buy_quantity} Get {$this->get_quantity}";
    }
}
