<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Illuminate\Support\Collection;

/**
 * Optional, Seller-configured purchase limits per product, per Buyer: a neutral
 * shop policy that protects Sellers from very large COD orders of a specific
 * product. It never flags, scores, restricts or notifies anyone; a blocked
 * attempt simply cannot be placed.
 *
 * Amounts are merchandise value in centavos, excluding shipping: the product's
 * line total minus its proportional share of the shop and platform merchandise
 * discounts allocated to its Seller. The same allocation is applied to the new
 * order (from the locked cart) and to existing orders (from the immutable
 * order_items and seller_orders checkout snapshot).
 *
 * Concurrency: placeOrder() calls assertWithinLimits() after it has locked the
 * Buyer row and the product rows (in id order); the cart lines carry those
 * locked product rows, and settings changes lock the same product row, so a
 * checkout sees either the old or the new limits, never a mix. The Buyer lock
 * serializes this Buyer's checkouts, so the exposure read (a plain read taken
 * after that lock) always includes the Buyer's previously committed orders.
 * No new lock is taken here, so the existing lock order is unchanged. Other
 * writers can only lower exposure (collection, cancellation), which at worst
 * makes the check briefly conservative.
 */
class PurchaseLimitService
{
    /** Completed, COD-paid orders at the same shop before a Buyer counts as trusted. */
    public const TRUSTED_COMPLETED_ORDERS = 3;

    /** Seller-order statuses that end COD exposure (closed or finally resolved). */
    public const CLOSED_STATUSES = [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::Lost];

    public const COLUMNS = ['purchase_limit_enabled', 'purchase_limit_max_quantity', 'purchase_limit_max_order_minor',
        'purchase_limit_max_active_minor', 'purchase_limit_exempt_trusted'];

    /** @var array<string, bool> Trusted-Buyer answers for the current check, keyed "buyer:seller". */
    private array $trusted = [];

    public static function available(): bool
    {
        foreach (self::COLUMNS as $column) {
            if (! RuntimeSchema::hasColumn('products', $column)) {
                return false;
            }
        }

        return true;
    }

    public static function limitsActive(Product $product): bool
    {
        return (bool) $product->purchase_limit_enabled
            && ($product->purchase_limit_max_quantity !== null
                || $product->purchase_limit_max_order_minor !== null
                || $product->purchase_limit_max_active_minor !== null);
    }

    /**
     * The first limit each product in the cart breaks, keyed by product id.
     * Empty when everything is within limits or the feature is not installed.
     *
     * @return array<int, string>
     */
    public function problems(Buyer $buyer, CartSummary $summary): array
    {
        if (! self::available()) {
            return [];
        }

        $this->trusted = [];
        $problems = [];
        foreach ($summary->sellerGroups as $group) {
            $sellerId = (int) $group['seller']->getKey();
            /** @var Collection<int, CartItem> $items */
            $items = $group['items'];
            $sellerSubtotal = (int) $items->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
            $sellerDiscount = max(0, $summary->voucherApplication->shopDiscountFor($sellerId)
                + $summary->voucherApplication->platformDiscountFor($sellerId));

            foreach ($items->groupBy('product_id') as $productId => $lines) {
                /** @var Product|null $product */
                $product = $lines->first()->product;
                if ($product === null || ! self::limitsActive($product)) {
                    continue;
                }
                $quantity = (int) $lines->sum('quantity');
                $lineTotal = (int) $lines->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
                $value = self::netOfDiscount($lineTotal, $sellerDiscount, $sellerSubtotal);

                $problem = $this->problemFor($buyer, $product, $quantity, $value);
                if ($problem !== null) {
                    $problems[(int) $productId] = $problem;
                }
            }
        }

        return $problems;
    }

    /** @throws CheckoutException */
    public function assertWithinLimits(Buyer $buyer, CartSummary $summary): void
    {
        $problems = $this->problems($buyer, $summary);
        if ($problems !== []) {
            throw new CheckoutException(reset($problems));
        }
    }

    private function problemFor(Buyer $buyer, Product $product, int $quantity, int $value): ?string
    {
        if ($product->purchase_limit_exempt_trusted && $this->isTrusted($buyer, (int) $product->seller_id)) {
            return null;
        }
        $name = $product->name;

        $maxQuantity = $product->purchase_limit_max_quantity;
        if ($maxQuantity !== null && $quantity > $maxQuantity) {
            return "This shop allows up to {$maxQuantity} of \"{$name}\" per order. Please reduce the quantity.";
        }

        $maxOrder = $product->purchase_limit_max_order_minor;
        if ($maxOrder !== null && $value > $maxOrder) {
            return 'This shop allows up to '.self::peso($maxOrder)." of \"{$name}\" per order, excluding shipping. Please reduce the quantity.";
        }

        $maxActive = $product->purchase_limit_max_active_minor;
        if ($maxActive !== null && $this->activeExposure($buyer, $product) + $value > $maxActive) {
            return 'This shop limits "'.$name.'" to '.self::peso($maxActive)
                .' per customer across orders that are not yet delivered, excluding shipping. You can order more once your current orders are delivered or cancelled.';
        }

        return null;
    }

    /**
     * Value of this product on the Buyer's open COD orders: not yet collected
     * (payment still pending) and not finally closed. Includes orders awaiting
     * pickup, in transit, out for delivery, and failed deliveries pending a
     * retry or return. Ends once the Rider records the exact COD collection
     * (payment becomes paid) or the order is cancelled, returned, completed or
     * lost (a cancellation also voids the pending payment).
     */
    public function activeExposure(Buyer $buyer, Product $product): int
    {
        return (int) SellerOrder::query()
            ->where('seller_orders.seller_id', $product->seller_id)
            ->whereNotIn('seller_orders.status', array_map(fn (OrderStatus $s) => $s->value, self::CLOSED_STATUSES))
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $buyer->getKey()))
            ->whereHas('payment', fn ($q) => $q->where('method', PaymentMethod::Cod->value)->where('status', PaymentStatus::Pending->value))
            ->whereHas('orderItems', fn ($q) => $q->where('product_id', $product->getKey()))
            ->with(['orderItems' => fn ($q) => $q->where('product_id', $product->getKey())])
            ->get(['seller_orders.id', 'subtotal_minor', 'voucher_discount_minor', 'shop_voucher_discount_minor', 'platform_voucher_discount_minor'])
            ->sum(fn (SellerOrder $parcel): int => self::snapshotValue($parcel, $parcel->orderItems));
    }

    /**
     * Goods value of the given lines of an existing order, from its immutable
     * checkout snapshot, excluding shipping.
     *
     * @param  iterable<OrderItem>  $items
     */
    public static function snapshotValue(SellerOrder $parcel, iterable $items): int
    {
        $lineTotal = 0;
        foreach ($items as $item) {
            $lineTotal += (int) $item->price_minor * (int) $item->quantity;
        }
        $discount = ($parcel->shop_voucher_discount_minor !== null || $parcel->platform_voucher_discount_minor !== null)
            ? (int) $parcel->shop_voucher_discount_minor + (int) $parcel->platform_voucher_discount_minor
            // Older rows carry only the combined merchandise discount.
            : (int) $parcel->voucher_discount_minor;

        return self::netOfDiscount($lineTotal, $discount, (int) $parcel->subtotal_minor);
    }

    /** The line total minus its proportional share of the Seller's merchandise discount (never below zero). */
    public static function netOfDiscount(int $lineTotal, int $discount, int $sellerSubtotal): int
    {
        if ($lineTotal <= 0) {
            return 0;
        }
        if ($sellerSubtotal <= 0 || $discount <= 0) {
            return $lineTotal;
        }
        $discount = min($discount, $sellerSubtotal);
        $share = intdiv($discount * min($lineTotal, $sellerSubtotal), $sellerSubtotal);

        return max(0, $lineTotal - $share);
    }

    /** At least TRUSTED_COMPLETED_ORDERS completed orders at this shop whose COD was collected and paid. */
    public function isTrusted(Buyer $buyer, int $sellerId): bool
    {
        return $this->trusted[$buyer->getKey().':'.$sellerId] ??= SellerOrder::query()
            ->where('seller_id', $sellerId)
            ->where('status', OrderStatus::Completed->value)
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $buyer->getKey()))
            ->whereHas('payment', fn ($q) => $q->where('method', PaymentMethod::Cod->value)->where('status', PaymentStatus::Paid->value))
            ->limit(self::TRUSTED_COMPLETED_ORDERS)
            ->count() >= self::TRUSTED_COMPLETED_ORDERS;
    }

    public static function peso(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }
}
