<?php

namespace App\Services;

use App\Models\SellerOrder;
use App\Models\SellerSettlement;
use App\Models\Voucher;

class VoucherFundingService
{
    /**
     * Interpret immutable checkout snapshots, never today's Voucher settings.
     * Caller holds the SellerOrder lock; parent checkout totals are immutable.
     *
     * @return array{discount_minor: ?int, seller_minor: ?int, marketplace_minor: ?int, pending_reason: ?string}
     */
    public function allocationFor(SellerOrder $sellerOrder): array
    {
        $parentDiscount = $sellerOrder->order()->value('discount_minor');
        $discount = $sellerOrder->voucher_discount_minor;

        if ($parentDiscount === null) {
            return $this->unresolved($discount, SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED);
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
            return ['discount_minor' => 0, 'seller_minor' => 0, 'marketplace_minor' => 0, 'pending_reason' => null];
        }

        return match ($sellerOrder->voucher_funding_source) {
            Voucher::FUNDING_SELLER => [
                'discount_minor' => $discount, 'seller_minor' => $discount, 'marketplace_minor' => 0, 'pending_reason' => null,
            ],
            Voucher::FUNDING_MARKETPLACE => [
                'discount_minor' => $discount, 'seller_minor' => 0, 'marketplace_minor' => $discount, 'pending_reason' => null,
            ],
            default => $this->unresolved($discount, SellerSettlement::VOUCHER_FUNDING_UNKNOWN),
        };
    }

    /** @return array{discount_minor: ?int, seller_minor: ?int, marketplace_minor: ?int, pending_reason: string} */
    private function unresolved(?int $discount, string $reason): array
    {
        return ['discount_minor' => $discount, 'seller_minor' => null, 'marketplace_minor' => null, 'pending_reason' => $reason];
    }
}
