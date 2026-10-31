<?php

namespace App\Services\Buyer;

use App\Enums\VoucherScope;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Support\Collection;

/**
 * Immutable result of validating and stacking the buyer's vouchers.
 *
 * Slot rules:
 *  - one platform voucher for the whole order;
 *  - one shop voucher per selected Seller;
 *  - one shipping voucher for the order's shipping fees.
 *
 * Discount order is deterministic: shop vouchers reduce their own Seller's
 * merchandise, the platform voucher then reduces the remaining merchandise,
 * and the shipping voucher reduces only the quoted shipping fees. Every
 * discount is capped by the amount it can actually reduce.
 */
final class VoucherApplication
{
    /** @var Collection<int, Voucher> */
    public readonly Collection $vouchers;

    /** @var array<int, Voucher> keyed by Seller id */
    public readonly array $shopVouchers;

    public readonly ?Voucher $platformVoucher;

    public readonly ?Voucher $shippingVoucher;

    /** @var array<int, int> Seller id => shop discount in centavos */
    public readonly array $shopDiscounts;

    /** @var array<int, int> Seller id => platform discount in centavos */
    public readonly array $platformDiscounts;

    /** @var array<int, int> Seller id => shipping discount in centavos */
    public readonly array $shippingDiscounts;

    public readonly int $shopDiscountCentavos;

    public readonly int $platformDiscountCentavos;

    public readonly int $shippingDiscountCentavos;

    public readonly int $discountCentavos;

    /** @var array<int, int> Seller id => quoted shipping fee in centavos */
    public readonly array $shippingFees;

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @param  Collection<int, array{seller: Seller, items: Collection}>  $sellerGroups
     * @param  array<int, int>  $shippingFees  Seller id => quoted fee in centavos
     */
    public function __construct(
        Collection $vouchers,
        public readonly Collection $sellerGroups,
        array $shippingFees = [],
    ) {
        // Canonicalize every entry point: the latest voucher replaces its slot.
        $this->vouchers = $vouchers->keyBy(fn (Voucher $voucher): string => $voucher->slotKey())->values();

        $this->shopVouchers = $this->vouchers
            ->filter(fn (Voucher $voucher): bool => $voucher->effectiveScope() === VoucherScope::Shop)
            ->keyBy(fn (Voucher $voucher): int => (int) $voucher->seller_id)
            ->all();

        $this->platformVoucher = $this->vouchers
            ->first(fn (Voucher $voucher): bool => $voucher->effectiveScope() === VoucherScope::Platform);

        $this->shippingVoucher = $this->vouchers
            ->first(fn (Voucher $voucher): bool => $voucher->effectiveScope() === VoucherScope::Shipping);

        $subtotals = [];
        $itemsBySeller = [];
        foreach ($this->sellerGroups as $group) {
            $sellerId = (int) $group['seller']->getKey();
            $itemsBySeller[$sellerId] = $group['items'];
            $subtotals[$sellerId] = (int) $group['items']->sum(
                fn ($item): int => $item->lineTotalCentavos(),
            );
        }

        $this->shippingFees = array_map('intval', $shippingFees);

        $shopDiscounts = [];
        $remaining = [];
        foreach ($subtotals as $sellerId => $subtotal) {
            $shopVoucher = $this->shopVouchers[$sellerId] ?? null;
            $shopDiscounts[$sellerId] = $shopVoucher === null
                ? 0
                : $shopVoucher->discountCentavos($shopVoucher->eligibleSubtotal($itemsBySeller[$sellerId]));
            $remaining[$sellerId] = max(0, $subtotal - $shopDiscounts[$sellerId]);
        }

        $this->shopDiscounts = $shopDiscounts;
        $this->shopDiscountCentavos = array_sum($shopDiscounts);

        $platformBase = max(0, array_sum($subtotals) - $this->shopDiscountCentavos);
        $platformDiscount = $this->platformVoucher === null
            ? 0
            : min($this->platformVoucher->discountCentavos($platformBase), $platformBase);

        $this->platformDiscounts = $this->allocate($platformDiscount, $subtotals, $remaining);
        $this->platformDiscountCentavos = array_sum($this->platformDiscounts);

        $fees = [];
        foreach ($subtotals as $sellerId => $subtotal) {
            $fees[$sellerId] = max(0, (int) ($this->shippingFees[$sellerId] ?? 0));
        }
        $grossShipping = array_sum($fees);
        $shippingDiscount = $this->shippingVoucher === null || $grossShipping <= 0
            ? 0
            : min($this->shippingVoucher->discountCentavos($grossShipping), $grossShipping);

        $this->shippingDiscounts = $this->allocate($shippingDiscount, $fees);
        $this->shippingDiscountCentavos = array_sum($this->shippingDiscounts);

        // Legacy CartSummary field: merchandise discount only. Shipping is
        // tracked separately so it can never be mistaken for a product discount.
        $this->discountCentavos = $this->shopDiscountCentavos + $this->platformDiscountCentavos;
    }

    public static function empty(Collection $sellerGroups, array $shippingFees = []): self
    {
        return new self(collect(), $sellerGroups, $shippingFees);
    }

    public function hasVoucher(): bool
    {
        return $this->vouchers->isNotEmpty();
    }

    public function shopVoucherFor(int $sellerId): ?Voucher
    {
        return $this->shopVouchers[$sellerId] ?? null;
    }

    public function shopDiscountFor(int $sellerId): int
    {
        return (int) ($this->shopDiscounts[$sellerId] ?? 0);
    }

    public function platformDiscountFor(int $sellerId): int
    {
        return (int) ($this->platformDiscounts[$sellerId] ?? 0);
    }

    public function shippingDiscountFor(int $sellerId): int
    {
        return (int) ($this->shippingDiscounts[$sellerId] ?? 0);
    }

    public function shippingFeeFor(int $sellerId): int
    {
        return (int) ($this->shippingFees[$sellerId] ?? 0);
    }

    /**
     * Funding split for one Seller's checkout snapshot.
     *
     * Both values are null when any applied voucher has no recorded funder:
     * settlement must stop for reconciliation rather than guessing.
     *
     * @return array{seller_minor: ?int, marketplace_minor: ?int}
     */
    public function fundingFor(int $sellerId): array
    {
        $seller = 0;
        $marketplace = 0;

        foreach ($this->fundingComponentsFor($sellerId) as [$voucher, $amount]) {
            if ($amount <= 0) {
                continue;
            }

            if ($voucher->funding_source === Voucher::FUNDING_SELLER) {
                $seller += $amount;
            } elseif ($voucher->funding_source === Voucher::FUNDING_MARKETPLACE) {
                $marketplace += $amount;
            } else {
                return ['seller_minor' => null, 'marketplace_minor' => null];
            }
        }

        return ['seller_minor' => $seller, 'marketplace_minor' => $marketplace];
    }

    /**
     * @return list<array{slot: string, scope: string, code: string, seller_id: ?int, label: string, discount_minor: int, discount_formatted: string, funding_source: ?string}>
     */
    public function toArray(): array
    {
        $rows = [];

        foreach ($this->vouchers as $voucher) {
            $scope = $voucher->effectiveScope();
            $discount = match ($scope) {
                VoucherScope::Shop => $this->shopDiscountFor((int) $voucher->seller_id),
                VoucherScope::Platform => $this->platformDiscountCentavos,
                VoucherScope::Shipping => $this->shippingDiscountCentavos,
            };

            $rows[] = [
                'slot' => $voucher->slotKey(),
                'scope' => $scope->value,
                'code' => $voucher->code,
                'seller_id' => $voucher->seller_id !== null ? (int) $voucher->seller_id : null,
                'label' => $voucher->code.' (-₱'.number_format($discount / 100, 2).')',
                'discount_minor' => $discount,
                'discount_formatted' => '-₱'.number_format($discount / 100, 2),
                'funding_source' => $voucher->funding_source,
            ];
        }

        return $rows;
    }

    /** @return list<int> */
    public function voucherIds(): array
    {
        return $this->vouchers
            ->map(fn (Voucher $voucher): int => (int) $voucher->getKey())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Identifies the vouchers and merchandise discount the buyer reviewed.
     * Shipping discounts are left out: they move with the carrier the buyer picks.
     */
    public function reviewFingerprint(): string
    {
        return hash('sha256', implode(',', $this->voucherIds()).'|'.$this->discountCentavos);
    }

    /**
     * @return list<array{0: Voucher, 1: int}>
     */
    private function fundingComponentsFor(int $sellerId): array
    {
        $components = [];

        $shopVoucher = $this->shopVoucherFor($sellerId);
        if ($shopVoucher !== null) {
            $components[] = [$shopVoucher, $this->shopDiscountFor($sellerId)];
        }

        if ($this->platformVoucher !== null) {
            $components[] = [$this->platformVoucher, $this->platformDiscountFor($sellerId)];
        }

        if ($this->shippingVoucher !== null) {
            $components[] = [$this->shippingVoucher, $this->shippingDiscountFor($sellerId)];
        }

        return $components;
    }

    /**
     * Split a discount across weights. Platform discounts use subtotal weights
     * and put the remainder on the largest store, subject to its remaining
     * merchandise. Shipping uses the largest fractional remainder.
     * The result always sums exactly to the discount and never exceeds a
     * weight.
     *
     * @param  array<int, int>  $weights
     * @param  array<int, int>|null  $capacities  Platform discount ceilings after shop discounts.
     * @return array<int, int>
     */
    private function allocate(int $discount, array $weights, ?array $capacities = null): array
    {
        $total = array_sum($weights);

        if ($discount <= 0 || $total <= 0) {
            return array_map(fn (): int => 0, $weights);
        }

        $discount = min($discount, $total);
        $allocated = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $weight = max(0, (int) $weight);
            $weighted = bcmul((string) $discount, (string) $weight, 0);
            $allocated[$key] = (int) bcdiv($weighted, (string) $total, 0);
            if ($capacities !== null) {
                $allocated[$key] = min($allocated[$key], max(0, $capacities[$key] ?? 0));
            }
            $remainders[$key] = (int) bcmod($weighted, (string) $total);
        }

        $left = $discount - array_sum($allocated);
        if ($capacities !== null) {
            // Platform shares use store subtotals. Give all leftover centavos
            // to the largest store with room; seller id breaks equal-subtotal ties.
            $keys = array_keys($weights);
            usort($keys, fn ($a, $b): int => ($weights[$b] <=> $weights[$a]) ?: ($a <=> $b));
            foreach ($keys as $key) {
                $extra = min($left, max(0, ($capacities[$key] ?? 0) - $allocated[$key]));
                $allocated[$key] += $extra;
                $left -= $extra;
                if ($left === 0) {
                    break;
                }
            }

            return $allocated;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }

            // A zero-weight Seller cannot receive a centavo.
            if ($weights[$key] <= 0 || $allocated[$key] >= $weights[$key]) {
                continue;
            }

            $allocated[$key]++;
            $left--;
        }

        return $allocated;
    }
}
