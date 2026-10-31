<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SellerEarningsService
{
    public function __construct(
        private readonly SellerPayoutRequestService $payouts = new SellerPayoutRequestService,
    ) {}

    /**
     * Pending is finalized net still waiting on remittance. Available is the
     * withdrawable balance. Net is gross merchandise minus seller store sales,
     * minus seller vouchers, minus 5% commission. Platform vouchers and shipping
     * are reported and never deducted.
     *
     * @return array{
     *     pendingMinor: int,
     *     availableMinor: int,
     *     reservedMinor: int,
     *     grossMinor: int,
     *     salesMinor: int,
     *     sellerVoucherMinor: int,
     *     commissionMinor: int,
     *     netMinor: int,
     *     platformVoucherMinor: int,
     *     shippingMinor: int,
     *     rows: Collection<int, array<string, mixed>>
     * }
     */
    public function summary(Seller $seller): array
    {
        $availableIds = $this->payouts->availableSellerOrderIds($seller);
        $orders = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->where('status', OrderStatus::Completed)
            ->whereNotNull('commission_finalized_at')
            ->whereNotNull('commission_bps')
            ->whereNotNull('commission_minor')
            ->whereNotNull('seller_net_minor')
            ->with(['orderItems', 'settlement', 'order', 'parcelReturn'])
            ->orderByDesc('id')
            ->get();

        $pendingMinor = 0;
        $grossMinor = 0;
        $salesMinor = 0;
        $sellerVoucherMinor = 0;
        $commissionMinor = 0;
        $netMinor = 0;
        $platformVoucherMinor = 0;
        $shippingMinor = 0;
        $rows = collect();

        foreach ($orders as $order) {
            $bucket = $this->bucket($order, $availableIds);
            if (! in_array($bucket, ['pending', 'available'], true)) {
                continue;
            }

            $line = $this->line($order);
            if ($bucket === 'pending') {
                $pendingMinor += $line['netMinor'];
            }

            $grossMinor += $line['grossMinor'];
            $salesMinor += $line['salesMinor'];
            $sellerVoucherMinor += $line['sellerVoucherMinor'];
            $commissionMinor += $line['commissionMinor'];
            $netMinor += $line['netMinor'];
            $platformVoucherMinor += $line['platformVoucherMinor'];
            $shippingMinor += $line['shippingMinor'];
            $rows->push($line + [
                'bucket' => $bucket,
                'reference' => $order->order?->reference ?? ('#'.$order->id),
            ]);
        }

        return [
            'pendingMinor' => $pendingMinor,
            'availableMinor' => $this->payouts->availableBalance($seller),
            'reservedMinor' => $this->payouts->reservedMinor($seller),
            'grossMinor' => $grossMinor,
            'salesMinor' => $salesMinor,
            'sellerVoucherMinor' => $sellerVoucherMinor,
            'commissionMinor' => $commissionMinor,
            'netMinor' => $netMinor,
            'platformVoucherMinor' => $platformVoucherMinor,
            'shippingMinor' => $shippingMinor,
            'rows' => $rows,
        ];
    }

    /**
     * Sales, commission, and net for completed orders whose earnings were
     * finalized inside the inclusive range. Platform vouchers and shipping
     * stay out of the net.
     *
     * @return array{
     *     salesMinor: int,
     *     grossMinor: int,
     *     storeSaleMinor: int,
     *     sellerVoucherMinor: int,
     *     commissionMinor: int,
     *     netMinor: int,
     *     platformVoucherMinor: int,
     *     shippingMinor: int,
     *     rows: Collection<int, array<string, mixed>>
     * }
     */
    public function report(Seller $seller, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->where('status', OrderStatus::Completed)
            ->whereNotNull('commission_finalized_at')
            ->whereNotNull('commission_bps')
            ->whereNotNull('commission_minor')
            ->whereNotNull('seller_net_minor')
            ->where('commission_finalized_at', '>=', $from)
            ->where('commission_finalized_at', '<=', $to)
            ->with(['orderItems', 'order'])
            ->orderBy('commission_finalized_at')
            ->orderBy('id')
            ->get();

        $totals = [
            'salesMinor' => 0,
            'grossMinor' => 0,
            'storeSaleMinor' => 0,
            'sellerVoucherMinor' => 0,
            'commissionMinor' => 0,
            'netMinor' => 0,
            'platformVoucherMinor' => 0,
            'shippingMinor' => 0,
        ];
        $rows = collect();

        foreach ($orders as $order) {
            $line = $this->line($order);
            $charged = $line['grossMinor'] - $line['salesMinor'];
            $totals['salesMinor'] += $charged;
            $totals['grossMinor'] += $line['grossMinor'];
            $totals['storeSaleMinor'] += $line['salesMinor'];
            $totals['sellerVoucherMinor'] += $line['sellerVoucherMinor'];
            $totals['commissionMinor'] += $line['commissionMinor'];
            $totals['netMinor'] += $line['netMinor'];
            $totals['platformVoucherMinor'] += $line['platformVoucherMinor'];
            $totals['shippingMinor'] += $line['shippingMinor'];
            $rows->push([
                'reference' => $order->order?->reference ?? ('#'.$order->id),
                'finalizedAt' => $order->commission_finalized_at,
                'salesMinor' => $charged,
                'commissionMinor' => $line['commissionMinor'],
                'netMinor' => $line['netMinor'],
            ]);
        }

        return $totals + ['rows' => $rows];
    }

    /** @param  list<int>  $availableIds */
    private function bucket(SellerOrder $order, array $availableIds): string
    {
        $settlement = $order->settlement;
        if ($settlement?->status === SellerSettlement::PAID) {
            return 'paid';
        }
        if ($settlement?->status === SellerSettlement::RELEASED || $order->parcelReturn?->releasesPendingEarnings()) {
            return 'released';
        }
        if (in_array((int) $order->id, $availableIds, true)) {
            return 'available';
        }

        return 'pending';
    }

    /** @return array<string, int> */
    private function line(SellerOrder $order): array
    {
        $charged = 0;
        $gross = 0;
        foreach ($order->orderItems as $item) {
            $charged += $item->lineTotalMinor();
            $gross += $this->regularLineMinor($item);
        }
        $sales = max(0, $gross - $charged);
        if ($order->orderItems->isEmpty()) {
            $charged = (int) $order->subtotal_minor;
            $gross = (int) $order->subtotal_minor;
            $sales = 0;
        } elseif ($charged !== (int) $order->subtotal_minor) {
            $charged = (int) $order->subtotal_minor;
            $gross = $charged + $sales;
        }

        return [
            'grossMinor' => $gross,
            'salesMinor' => $sales,
            'sellerVoucherMinor' => $this->sellerMerchandiseVoucher($order),
            'commissionMinor' => (int) $order->commission_minor,
            'netMinor' => (int) $order->seller_net_minor,
            'platformVoucherMinor' => $this->platformVoucher($order),
            'shippingMinor' => (int) ($order->shipping_fee_minor ?? 0),
        ];
    }

    private function regularLineMinor(OrderItem $item): int
    {
        $regular = $item->regular_price_minor ?? $item->price_minor;

        return $item->quantity * $regular;
    }

    private function sellerMerchandiseVoucher(SellerOrder $order): int
    {
        if ($order->shop_voucher_discount_minor !== null) {
            return $order->shop_voucher_funding_source === Voucher::FUNDING_MARKETPLACE
                ? 0
                : (int) $order->shop_voucher_discount_minor;
        }

        if ($order->seller_funded_discount_minor !== null) {
            $shipping = $order->shipping_voucher_funding_source === Voucher::FUNDING_SELLER
                ? (int) ($order->shipping_discount_minor ?? $order->shipping_voucher_discount_minor ?? 0)
                : 0;

            return max(0, (int) $order->seller_funded_discount_minor - $shipping);
        }

        if ($order->voucher_funding_source === Voucher::FUNDING_SELLER) {
            return (int) ($order->voucher_discount_minor ?? 0);
        }

        return 0;
    }

    private function platformVoucher(SellerOrder $order): int
    {
        if ($order->platform_voucher_discount_minor !== null) {
            return (int) $order->platform_voucher_discount_minor;
        }

        if ($order->voucher_funding_source === Voucher::FUNDING_MARKETPLACE) {
            return (int) ($order->voucher_discount_minor ?? 0);
        }

        return (int) ($order->marketplace_funded_discount_minor ?? 0);
    }
}
