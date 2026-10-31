<?php

namespace App\Services\Shared;

use App\Enums\OrderStatus;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerSettlementService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CommissionFinalizationService
{
    public const COMMISSION_BPS = 500;

    public function __construct(
        private readonly SellerSettlementService $settlements = new SellerSettlementService,
        private readonly VoucherFundingService $funding = new VoucherFundingService,
    ) {}

    public function finalize(SellerOrder $sellerOrder): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder): SellerOrder {
            $order = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Completed) {
                throw new \InvalidArgumentException('Commission can only be finalized for a completed Seller order.');
            }

            $order->load('parcelReturn');
            if ($order->parcelReturn?->releasesPendingEarnings()) {
                $this->settlements->releasePendingForReturn($order);

                return $order->fresh();
            }

            if ($order->subtotal_minor === null || $order->subtotal_minor < 0) {
                throw new \InvalidArgumentException('Commission requires a non-negative recorded subtotal.');
            }

            // Half-up in integer centavos on merchandise subtotal after seller-funded discounts, excluding shipping.
            $allocation = $this->funding->allocationFor($order);
            $sellerFundedDiscount = $order->seller_funded_discount_minor ?? $allocation['seller_minor'] ?? 0;
            $baseForCommission = $order->subtotal_minor - $sellerFundedDiscount;
            $commissionMinor = Money::percentage($baseForCommission, self::COMMISSION_BPS, 10000);
            $sellerNetMinor = $allocation['pending_reason'] === null
                ? $order->subtotal_minor - $commissionMinor - $allocation['seller_minor']
                : null;

            if ($order->commission_finalized_at === null) {
                if ($order->commission_bps !== null || $order->commission_minor !== null || $order->seller_net_minor !== null) {
                    throw new \InvalidArgumentException('Partial commission records require reconciliation before finalization.');
                }

                $order->forceFill([
                    'commission_bps' => self::COMMISSION_BPS,
                    'commission_minor' => $commissionMinor,
                    'seller_net_minor' => $sellerNetMinor,
                    'commission_finalized_at' => now(),
                ])->save();
            } else {
                if ($order->commission_bps !== self::COMMISSION_BPS || $order->commission_minor !== $commissionMinor
                    || ($sellerNetMinor !== null && $order->seller_net_minor !== null && $order->seller_net_minor !== $sellerNetMinor)) {
                    throw new \InvalidArgumentException('Finalized commission does not match the recorded subtotal and voucher funding.');
                }

                // A formerly unknown net can only be resolved from a recorded allocation.
                if ($order->seller_net_minor === null && $sellerNetMinor !== null) {
                    $order->forceFill(['seller_net_minor' => $sellerNetMinor])->save();
                }
            }

            $this->settlements->syncFinalizedOrder($order);

            return $order;
        }, 3);
    }
}
