<?php

namespace App\Models\Seller;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCategory extends Model
{
    protected $guarded = ['*'];

    protected $attributes = ['sort_order' => 0, 'is_visible' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_visible' => 'boolean'];
    }

    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function isPubliclyVisible(): bool
    {
        return $this->is_visible && ($this->parent_id === null || ($this->parent?->is_visible === true
            && (int) $this->parent->seller_id === (int) $this->seller_id && $this->parent->parent_id === null));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->displayOrder();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_store_category', 'store_category_id', 'product_id')->withTimestamps();
    }
}
