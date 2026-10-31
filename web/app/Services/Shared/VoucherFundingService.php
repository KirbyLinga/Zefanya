<?php

namespace App\Services\Shared;

use App\Models\Seller\SellerSettlement;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;

class VoucherFundingService
{
    /**
     * Interpret immutable checkout snapshots, never today's Voucher settings.
     * Caller holds the SellerOrder lock; parent checkout totals are immutable.
     *
     * `discount_minor` is the merchandise discount (legacy contract).
     * Shipping discounts are returned separately so COD reconciliation never
     * mistakes them for product discounts.
     *
     * @return array{
     *     discount_minor: ?int,
     *     shipping_discount_minor: int,
     *     seller_minor: ?int,
     *     marketplace_minor: ?int,
     *     pending_reason: ?string
     * }
     */
    public function allocationFor(SellerOrder $sellerOrder): array
    {
        $parentDiscount = $sellerOrder->order()->value('discount_minor');
        $discount = $sellerOrder->voucher_discount_minor;

        if ($parentDiscount === null) {
            return $this->unresolved($discount, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
        }

        if ($this->hasStackSnapshot($sellerOrder)) {
            return $this->stackAllocation($sellerOrder, (int) $parentDiscount);
        }

        if ($discount === null) {
            // Only an explicitly undiscounted parent is safe for legacy rows.
            if ((int) $parentDiscount !== 0) {
                return $this->unresolved(null, SellerSettlement::VOUCHER_FUNDING_UNKNOWN);
            }

            $discount = 0;
        }

        if ($discount < 0 || $discount > $sellerOrder->subtotal_minor || $discount > (int) $parentDiscount) {
            return $this->unresolved($discount, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
        }

        if ($discount === 0) {
            return $this->allocation(0, 0, 0, 0);
        }

        return match ($sellerOrder->voucher_funding_source) {
            Voucher::FUNDING_SELLER => $this->allocation($discount, 0, $discount, 0),
            Voucher::FUNDING_MARKETPLACE => $this->allocation($discount, 0, 0, $discount),
            default => $this->unresolved($discount, SellerSettlement::VOUCHER_FUNDING_UNKNOWN),
        };
    }

    private function hasStackSnapshot(SellerOrder $sellerOrder): bool
    {
        foreach ([
            'shop_voucher_discount_minor',
            'platform_voucher_discount_minor',
            'shipping_voucher_discount_minor',
            'shipping_discount_minor',
            'seller_funded_discount_minor',
            'marketplace_funded_discount_minor',
        ] as $field) {
            if ($sellerOrder->{$field} !== null) {
                return true;
            }
        }

        return false;
    }

    private function stackAllocation(SellerOrder $sellerOrder, int $parentDiscount): array
    {
        $shop = (int) ($sellerOrder->shop_voucher_discount_minor ?? 0);
        $platform = (int) ($sellerOrder->platform_voucher_discount_minor ?? 0);
        $merchandise = $shop + $platform;
        $shipping = (int) ($sellerOrder->shipping_discount_minor
            ?? $sellerOrder->shipping_voucher_discount_minor
            ?? 0);

        $recorded = $sellerOrder->voucher_discount_minor;
        if ($recorded !== null && (int) $recorded !== $merchandise) {
            return $this->unresolved($recorded, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
        }

        if ($merchandise !== $parentDiscount
            || $merchandise < 0 || $merchandise > $sellerOrder->subtotal_minor
            || $shipping < 0 || $shipping > (int) $sellerOrder->shipping_fee_minor) {
            return $this->unresolved($recorded, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
        }

        $seller = $sellerOrder->seller_funded_discount_minor;
        $marketplace = $sellerOrder->marketplace_funded_discount_minor;

        if ($seller === null || $marketplace === null) {
            [$seller, $marketplace] = $this->fundingFromSources($sellerOrder, $shop, $platform, $shipping);
        }

        if ($seller === null || $marketplace === null
            || $seller < 0 || $marketplace < 0
            || $seller + $marketplace !== $merchandise + $shipping) {
            return $this->unresolved($recorded, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
        }

        return $this->allocation($merchandise, $shipping, $seller, $marketplace);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function fundingFromSources(SellerOrder $sellerOrder, int $shop, int $platform, int $shipping): array
    {
        $seller = 0;
        $marketplace = 0;

        foreach ([
            [$shop, $sellerOrder->shop_voucher_funding_source],
            [$platform, $sellerOrder->platform_voucher_funding_source],
            [$shipping, $sellerOrder->shipping_voucher_funding_source],
        ] as [$amount, $source]) {
            if ($amount <= 0) {
                continue;
            }

            if ($source === Voucher::FUNDING_SELLER) {
                $seller += $amount;
            } elseif ($source === Voucher::FUNDING_MARKETPLACE) {
                $marketplace += $amount;
            } else {
                return [null, null];
            }
        }

        return [$seller, $marketplace];
    }

    /** @return array{discount_minor: int, shipping_discount_minor: int, seller_minor: int, marketplace_minor: int, pending_reason: null} */
    private function allocation(int $discount, int $shipping, int $seller, int $marketplace): array
    {
        return [
            'discount_minor' => $discount,
            'shipping_discount_minor' => $shipping,
            'seller_minor' => $seller,
            'marketplace_minor' => $marketplace,
            'pending_reason' => null,
        ];
    }

    /** @return array{discount_minor: ?int, shipping_discount_minor: int, seller_minor: ?int, marketplace_minor: ?int, pending_reason: string} */
    private function unresolved(?int $discount, string $reason): array
    {
        return [
            'discount_minor' => $discount,
            'shipping_discount_minor' => 0,
            'seller_minor' => null,
            'marketplace_minor' => null,
            'pending_reason' => $reason,
        ];
    }
}
