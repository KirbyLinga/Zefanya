<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One selectable value on an option axis ("Red" under "Color"). Values are
 * shared across combinations — the same "Red" backs both "Red / M" and
 * "Red / L" — and are scoped per product through their option row.
 */
class ProductOptionValue extends Model
{
    protected $fillable = [
        'product_option_id',
        'value',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_option_values');
    }
}
