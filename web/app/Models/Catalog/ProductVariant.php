<?php

namespace App\Models\Catalog;

use App\Models\Buyer\CartItem;
use App\Models\Shared\OrderItem;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One purchasable combination of a product's options ("Red / M"). Even
 * single-SKU products have exactly one auto-generated Default variant
 * (strategy (a)) — price and stock are authoritative HERE, while the
 * product-level price/stock columns are maintained rollups kept in sync by
 * ProductObserver / VariantSyncService.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'stock_quantity',
        'is_available',
        'weight_grams',
        'dimensions',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_available' => 'boolean',
        'weight_grams' => 'integer',
        'dimensions' => 'array',
    ];

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_values')
            ->orderBy('product_option_value_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'variant_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'variant_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** True when this variant was generated from product options (not the auto Default). */
    public function hasOptions(): bool
    {
        return $this->optionValues->isNotEmpty();
    }

    /**
     * Human label: the Default variant reads "Default"; option variants read
     * "Red / M" (values joined in option sort order).
     */
    public function label(): string
    {
        if (! $this->hasOptions()) {
            return 'Default';
        }

        return $this->optionValues->pluck('value')->implode(' / ');
    }

    public function formattedPrice(): string
    {
        return '₱'.number_format((float) $this->price, 2);
    }

    public function isPurchaseable(): bool
    {
        return $this->is_available && $this->product !== null && $this->stock_quantity > 0;
    }

    /**
     * Parcel weight: the variant's own weight, or the product's when the
     * variant does not override it (NULL = inherit).
     */
    public function effectiveWeightGrams(): int
    {
        return (int) ($this->weight_grams ?? $this->product?->weight_grams ?? 0);
    }
}
