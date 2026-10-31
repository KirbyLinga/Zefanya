<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One option axis for a product (e.g. "Color", "Size"). At most two axes per
 * product is the app-level contract — the variant generator refuses more.
 */
class ProductOption extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductOptionValue> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
