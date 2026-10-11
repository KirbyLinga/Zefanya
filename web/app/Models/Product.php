<?php

namespace App\Models;

use App\Models\Concerns\BackfillsDefaultVariant;
use App\Models\Concerns\SyncsVariantRollups;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use BackfillsDefaultVariant, HasFactory, SoftDeletes, SyncsVariantRollups;

    protected $fillable = [
        'seller_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock_quantity',
        'low_stock_threshold',
        'status',
        'weight_grams',
        'dimensions',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'weight_grams' => 'integer',
        'dimensions' => 'array',
    ];

    /**
     * Total parcel weight in grams for this product (weight_grams × 1, since a
     * product row is one SKU). Used by the courier-assignment threshold check.
     */
    public function weightGrams(): int
    {
        return (int) $this->weight_grams;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * True when the seller generated option combinations for this product
     * (i.e. more than the auto "Default" variant). False = legacy single-SKU
     * behaviour: the Default variant simply mirrors the product's price/stock.
     */
    public function hasVariants(): bool
    {
        return $this->variants->contains(fn (ProductVariant $variant): bool => $variant->hasOptions());
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeActiveInStock(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('stock_quantity', '>', 0);
    }

    public function formattedPrice(): string
    {
        return '₱'.number_format((float) $this->price, 2);
    }

    public function catalogImageUrl(): ?string
    {
        $image = $this->images->firstWhere('is_primary', true) ?? $this->images->first();

        return $image !== null ? Storage::url($image->path) : null;
    }

    public static function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        return $slug;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->low_stock_threshold && $this->stock_quantity > 0;
    }
}
