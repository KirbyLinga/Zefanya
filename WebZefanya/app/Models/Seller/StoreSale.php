<?php

namespace App\Models\Seller;

use App\Enums\StoreSaleStatus;
use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Database\Factories\Seller\StoreSaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A timed discount a Seller runs on one product (variant_id null) or on one of
 * its variants. A sale is Ongoing from starts_at up to, but not including,
 * ends_at; status() and the query scopes below share that boundary.
 */
class StoreSale extends Model
{
    public const MIN_PERCENT = 1;

    public const MAX_PERCENT = 99;

    /** Sellers enter and read sale times in this zone; they are stored in app UTC. */
    public const TIMEZONE = 'Asia/Manila';

    /** @use HasFactory<StoreSaleFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'product_id',
        'variant_id',
        'type',
        'value',
        'flash_quantity',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'type' => StoreSaleType::class,
        'value' => 'integer',
        'flash_quantity' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $sale): void {
            $sale->assertValidDiscount();

            if ($sale->starts_at === null || $sale->ends_at === null || ! $sale->ends_at->gt($sale->starts_at)) {
                throw new \InvalidArgumentException('A sale must end after it starts.');
            }

            $sale->assertValidTarget();
        });
    }

    protected static function newFactory(): StoreSaleFactory
    {
        return StoreSaleFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // ── Schedule ─────────────────────────────────────────────────────────────

    public function status(): StoreSaleStatus
    {
        $now = now();

        return match (true) {
            $now->lt($this->starts_at) => StoreSaleStatus::Upcoming,
            $now->lt($this->ends_at) => StoreSaleStatus::Ongoing,
            default => StoreSaleStatus::Ended,
        };
    }

    /** Human-readable discount, e.g. "20% off" or "₱150.00". */
    public function discountLabel(): string
    {
        $label = $this->type === StoreSaleType::Percent
            ? "{$this->value}% off"
            : '₱'.number_format($this->value / 100, 2);

        return $this->flash_quantity === null
            ? $label
            : "{$label} · {$this->flash_quantity} slots";
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>', now());
    }

    public function scopeOngoing(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('ends_at', '<=', now());
    }

    // ── Invariants ───────────────────────────────────────────────────────────

    /** Percent: whole number 1-99. Fixed price: whole centavos, at least 1. */
    private function assertValidDiscount(): void
    {
        if ($this->type === null) {
            throw new \InvalidArgumentException('A sale must be a percentage or a fixed price.');
        }

        // Read raw: the integer cast would turn 12.5 into 12 and hide the mistake.
        $value = filter_var($this->getAttributes()['value'] ?? null, FILTER_VALIDATE_INT);

        if ($this->type === StoreSaleType::Percent
            && ($value === false || $value < self::MIN_PERCENT || $value > self::MAX_PERCENT)) {
            throw new \InvalidArgumentException('A sale percentage must be a whole number from 1 to 99.');
        }

        if ($this->type === StoreSaleType::FixedPrice && ($value === false || $value < 1)) {
            throw new \InvalidArgumentException('A fixed sale price must be a whole number of centavos above zero.');
        }

        $flash = $this->getAttributes()['flash_quantity'] ?? null;
        if ($flash !== null && $flash !== '') {
            $flash = filter_var($flash, FILTER_VALIDATE_INT);
            if ($flash === false || $flash < 1) {
                throw new \InvalidArgumentException('Flash quantity must be a whole number of at least 1.');
            }
        }
    }

    /** The product must be this Seller's, and the variant must be that product's. */
    private function assertValidTarget(): void
    {
        $ownsProduct = Product::withTrashed()
            ->whereKey($this->product_id)
            ->where('seller_id', $this->seller_id)
            ->exists();

        if (! $ownsProduct) {
            throw new \InvalidArgumentException('A sale can only target one of the Seller\'s own products.');
        }

        if ($this->variant_id !== null
            && ! ProductVariant::query()->whereKey($this->variant_id)->where('product_id', $this->product_id)->exists()) {
            throw new \InvalidArgumentException('A sale variant must belong to the sale product.');
        }
    }
}
