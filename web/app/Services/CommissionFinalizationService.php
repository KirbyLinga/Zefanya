<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;

class CommissionFinalizationService
{
    public const COMMISSION_BPS = 1000;

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

            if ($order->subtotal_minor === null || $order->subtotal_minor < 0) {
                throw new \InvalidArgumentException('Commission requires a non-negative recorded subtotal.');
            }

            // Half-up in integer centavos on original products, excluding shipping.
            $commissionMinor = intdiv($order->subtotal_minor * self::COMMISSION_BPS + 5000, 10000);
            $allocation = $this->funding->allocationFor($order);
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
