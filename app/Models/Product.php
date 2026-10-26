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
use Illuminate\Support\Collection;
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
     * Accessor: Return product name in title case, trimmed
     */
    public function getTitleAttribute(): string
    {
        return trim(ucwords(strtolower($this->name ?? '')));
    }

    /**
     * Total parcel weight in grams for this product (weight_grams × 1, since a
     * product row is one SKU). Used by the courier-assignment threshold check.
     */
    public function weightGrams(): int
    {
        return (int) $this->weight_grams;
    }

    /** A listed product with no weight cannot be rated for courier assignment. */
    public function needsWeight(): bool
    {
        return (int) $this->weight_grams <= 0;
    }

    public function isListedForSale(): bool
    {
        return ! $this->trashed() && $this->status === 'active'
            && $this->seller !== null && ! $this->seller->isArchived()
            && $this->seller->status === 'approved';
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

    /** Variants eligible for a buyer to purchase; physical rollups stay separate. */
    public function purchasableVariants(): HasMany
    {
        return $this->variants()->where('is_available', true)->where('stock_quantity', '>', 0);
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

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function scopeWithBuyerCardData(Builder $query): Builder
    {
        return $query->withAvg('reviews', 'rating')->withCount('reviews')
            ->withMin('purchasableVariants as catalog_min_price', 'price')
            ->withMax('purchasableVariants as catalog_max_price', 'price')
            ->withSum('purchasableVariants as catalog_stock_quantity', 'stock_quantity')
            ->withCount('variants')
            ->withExists(['variants as has_option_variants' => fn ($query) => $query->whereHas('optionValues')]);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function wishlistSavedBy(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeActiveInStock(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereHas('purchasableVariants');
    }

    /** Price filters and both sort directions use the advertised starting price. */
    public function scopeWithBuyerPriceFilters(Builder $query, array $filters): Builder
    {
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && is_numeric($filters[$key])) {
                $query->where($this->buyerStartingPriceQuery(), $operator, (float) $filters[$key]);
            }
        }

        return match ($filters['sort'] ?? 'relevance') {
            'price_asc' => $query->orderBy($this->buyerStartingPriceQuery())->orderBy('id'),
            'price_desc' => $query->orderBy($this->buyerStartingPriceQuery(), 'desc')->orderBy('id'),
            'newest' => $query->latest('created_at')->latest('id'),
            'oldest' => $query->oldest('created_at')->oldest('id'),
            default => $query->latest('id'),
        };
    }

    private function buyerStartingPriceQuery(): Builder
    {
        return ProductVariant::query()->selectRaw('CAST(MIN(price) AS DECIMAL(14, 2))')
            ->whereColumn('product_id', $this->getTable().'.id')
            ->where('is_available', true)->where('stock_quantity', '>', 0);
    }

    public function buyerStockQuantity(): int
    {
        if (! $this->isListedForSale()) {
            return 0;
        }
        if ($this->relationLoaded('variants')) {
            return (int) $this->variants->where('is_available', true)->where('stock_quantity', '>', 0)->sum('stock_quantity');
        }
        if (array_key_exists('catalog_stock_quantity', $this->getAttributes())) {
            return (int) $this->catalog_stock_quantity;
        }

        return (int) $this->purchasableVariants()->sum('stock_quantity');
    }

    public function buyerIsOutOfStock(): bool
    {
        return $this->buyerStockQuantity() <= 0;
    }

    public function buyerIsLowStock(): bool
    {
        $stock = $this->buyerStockQuantity();

        return $stock > 0 && $stock <= $this->low_stock_threshold;
    }

    public function formattedPrice(): string
    {
        return '₱'.number_format((float) $this->price, 2);
    }

    /** Seller inventory includes paused and sold-out variants. */
    public function formattedSellerPriceRange(): string
    {
        $minimum = $this->variants->min('price') ?? $this->price;
        $maximum = $this->variants->max('price') ?? $minimum;
        $format = fn ($value): string => "\u{20B1}".number_format((float) $value, 2);

        return (float) $minimum === (float) $maximum
            ? $format($minimum) : $format($minimum)."\u{2013}".$format($maximum);
    }

    /** Buyer-facing range, without a query for each product card. */
    public function formattedPriceRange(): string
    {
        if (! $this->isListedForSale()) {
            return 'Unavailable';
        }
        if ($this->relationLoaded('variants')) {
            $available = $this->variants->where('is_available', true)->where('stock_quantity', '>', 0);
            $minimum = $available->min('price');
            $maximum = $available->max('price');
        } elseif (array_key_exists('catalog_min_price', $this->getAttributes())) {
            $minimum = $this->catalog_min_price;
            $maximum = $this->catalog_max_price;
        } else {
            $bounds = $this->purchasableVariants()->selectRaw('MIN(price) as minimum, MAX(price) as maximum')->first();
            $minimum = $bounds?->minimum;
            $maximum = $bounds?->maximum;
        }
        if ($minimum === null) {
            return 'Unavailable';
        }
        $format = fn ($value): string => "\u{20B1}".number_format((float) $value, 2);

        return (float) $minimum === (float) $maximum
            ? $format($minimum) : $format($minimum)."\u{2013}".$format($maximum);
    }

    public function catalogImageUrl(?Collection $images = null, bool $requireExistingFile = true): ?string
    {
        $disk = Storage::disk('public');
        $image = ($images ?? $this->images)->sortByDesc('is_primary')
            ->first(fn (ProductImage $image): bool => $disk->exists($image->path));

        // Public image URLs may be served by storage outside this PHP instance.
        if ($image === null && ! $requireExistingFile) {
            $image = ($images ?? $this->images)->sortByDesc('is_primary')
                ->first(fn (ProductImage $image): bool => filled($image->path));
        }

        if ($image === null) {
            return null;
        }
        $url = $disk->url($image->path);

        // Local uploads are served by this site; never send buyers to the
        // configured development host (for example localhost:8000).
        return config('filesystems.disks.public.driver') === 'local'
            ? (parse_url($url, PHP_URL_PATH) ?: '/storage/'.ltrim($image->path, '/'))
            : $url;
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
