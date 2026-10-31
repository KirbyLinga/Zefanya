<?php

namespace App\Models\Shared;

use App\Enums\OrderStatus;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use App\Support\Money;
use Database\Factories\Shared\VoucherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Voucher extends Model
{
    public const FUNDING_SELLER = 'seller';

    public const FUNDING_MARKETPLACE = 'marketplace';

    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'scope',
        'seller_id',
        'type',
        'value',
        'min_spend',
        'is_active',
        'expires_at',
        'usage_limit',
        'times_used',
        'funding_source',
        'max_discount',
        'starts_at',
        'per_buyer_limit',
        'is_claimable',
        'applies_to_all_products',
        'ended_at',
    ];

    protected $casts = [
        'scope' => VoucherScope::class,
        'seller_id' => 'integer',
        'type' => VoucherType::class,
        'value' => 'integer',
        'min_spend' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'usage_limit' => 'integer',
        'times_used' => 'integer',
        'max_discount' => 'integer',
        'starts_at' => 'datetime',
        'per_buyer_limit' => 'integer',
        'is_claimable' => 'boolean',
        'applies_to_all_products' => 'boolean',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $voucher): void {
            if (! in_array($voucher->funding_source, [null, self::FUNDING_SELLER, self::FUNDING_MARKETPLACE], true)) {
                throw new \InvalidArgumentException('Voucher funding must be seller, marketplace, or unknown.');
            }

            $scope = $voucher->scope instanceof VoucherScope
                ? $voucher->scope
                : VoucherScope::tryFrom((string) $voucher->scope);

            // Vouchers created before stacking support are platform vouchers.
            $scope ??= VoucherScope::Platform;
            $voucher->scope = $scope;

            if ($scope === VoucherScope::Shop && $voucher->seller_id === null) {
                throw new \InvalidArgumentException('A shop voucher requires a seller.');
            }

            if ($scope !== VoucherScope::Shop && $voucher->seller_id !== null) {
                throw new \InvalidArgumentException('Only a shop voucher may target a seller.');
            }
        });
    }

    protected static function newFactory(): VoucherFactory
    {
        return VoucherFactory::new();
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** The only products that count when applies_to_all_products is false. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'voucher_product');
    }

    /**
     * The part of these cart lines this voucher can discount and that counts
     * toward its minimum spend.
     *
     * @param  Collection<int, CartItem>  $items
     */
    public function eligibleSubtotal(Collection $items): int
    {
        $eligible = $this->applies_to_all_products === false
            ? $items->whereIn('product_id', $this->products->modelKeys())
            : $items;

        return (int) $eligible->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
    }

    public function appliesToProduct(int $productId): bool
    {
        return $this->applies_to_all_products !== false
            || $this->products->contains('id', $productId);
    }

    /**
     * Claimable shop vouchers a buyer could use right now, for showing on shop,
     * product and cart pages. Code-only vouchers are never listed.
     *
     * @param  array<int, int>  $sellerIds
     * @return Collection<int, self>
     */
    public static function offeredBy(array $sellerIds, ?int $buyerId = null): Collection
    {
        return static::query()
            ->where('scope', VoucherScope::Shop->value)
            ->whereIn('seller_id', $sellerIds)
            ->where('is_claimable', true)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit'))
            ->with('products:id')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (self $voucher): bool => $buyerId !== null
                && $voucher->per_buyer_limit !== null
                && $voucher->usesByBuyer($buyerId) >= $voucher->per_buyer_limit)
            ->values();
    }

    /** Buyer-facing terms, e.g. "10% off, up to ₱50.00 · Min. spend ₱200.00 · Ends Nov 3". */
    public function offerSummary(): string
    {
        $format = fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);

        $discount = $this->type === VoucherType::Percent
            ? "{$this->value}% off".($this->max_discount !== null ? ', up to '.$format($this->max_discount) : '')
            : $format($this->value).' off';

        return collect([
            $discount,
            $this->min_spend ? 'Min. spend '.$format($this->min_spend) : null,
            $this->applies_to_all_products === false ? 'Selected products' : null,
            $this->expires_at ? 'Ends '.$this->expires_at->copy()->timezone(StoreSale::TIMEZONE)->format('M j, Y') : null,
        ])->filter()->implode(' · ');
    }

    /**
     * Orders this buyer holds with the voucher. A cancelled shipment does not
     * count, matching the return of times_used on cancel. One order counts once
     * however many shipments share the voucher.
     */
    public function usesByBuyer(int $buyerId): int
    {
        return (int) $this->sellerOrders()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyerId))
            ->distinct()
            ->count('order_id');
    }

    public function hasBeenUsed(): bool
    {
        return $this->sellerOrders()->exists();
    }

    public function claims(): HasMany
    {
        return $this->hasMany(VoucherClaim::class);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * Once a buyer has claimed or used it, a voucher's rules are a promise: the
     * Seller may only extend the end date or raise a limit, or end it.
     */
    public function rulesLocked(): bool
    {
        return $this->claims()->exists() || $this->hasBeenUsed();
    }

    /** Buyers holding an order with this voucher; their wallet entries survive it ending. */
    public function buyerIdsWithOrders(): Collection
    {
        return Order::query()
            ->whereIn('id', $this->sellerOrders()->where('status', '!=', OrderStatus::Cancelled->value)->select('order_id'))
            ->pluck('buyer_id');
    }

    /** @return Builder<SellerOrder> Every shipment that applied this voucher, in any slot. */
    private function sellerOrders(): Builder
    {
        return SellerOrder::query()->where(fn ($query) => $query
            ->where('voucher_id', $this->id)
            ->orWhere('shop_voucher_id', $this->id)
            ->orWhere('platform_voucher_id', $this->id)
            ->orWhere('shipping_voucher_id', $this->id));
    }

    public function effectiveScope(): VoucherScope
    {
        return $this->scope instanceof VoucherScope ? $this->scope : VoucherScope::Platform;
    }

    /**
     * Stable session/stack key. A shop slot is unique per Seller, while the
     * platform and shipping slots are single-slot keys.
     */
    public function slotKey(): string
    {
        return $this->effectiveScope() === VoucherScope::Shop
            ? 'shop:'.$this->seller_id
            : $this->effectiveScope()->value;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calculate the discount in centavos for a given subtotal (also centavos).
     * Never returns more than the subtotal.
     */
    public function discountCentavos(int $subtotalCentavos): int
    {
        $discount = match ($this->type) {
            VoucherType::Fixed => $this->value,                        // already centavos
            VoucherType::Percent => Money::percentage($subtotalCentavos, $this->value),
            default => 0,
        };

        if ($this->max_discount !== null) {
            $discount = min($discount, $this->max_discount);
        }

        return min($discount, $subtotalCentavos);
    }

    /**
     * Validate the voucher against a subtotal and return a plain-English
     * rejection reason, or null if the voucher is valid.
     */
    public function rejectionReason(int $subtotalCentavos): ?string
    {
        if ($this->hasEnded()) {
            return 'This voucher has ended.';
        }

        if (! $this->is_active) {
            return 'This voucher is no longer active.';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'This voucher is not active yet.';
        }

        if ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now())) {
            return 'This voucher has expired.';
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            return 'This voucher has reached its usage limit.';
        }

        if ($this->min_spend !== null && $subtotalCentavos < $this->min_spend) {
            $formatted = '₱'.number_format(($this->min_spend - $subtotalCentavos) / 100, 2);

            return "Spend {$formatted} more to use this voucher.";
        }

        return null;
    }

    /** Seller-facing state, from the same rules buyers are held to. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->hasEnded() => 'Ended',
            ! $this->is_active => 'Inactive',
            $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now()) => 'Expired',
            $this->usage_limit !== null && $this->times_used >= $this->usage_limit => 'Used up',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'Upcoming',
            default => 'Active',
        };
    }

    /** Human-readable discount label, e.g. "-₱200.00" or "-10% (-₱56.96)". */
    public function discountLabel(int $subtotalCentavos): string
    {
        if ($this->type === VoucherType::Percent) {
            return "-{$this->value}% (-₱".number_format($this->discountCentavos($subtotalCentavos) / 100, 2).')';
        }

        return '-₱'.number_format($this->discountCentavos($subtotalCentavos) / 100, 2);
    }
}
