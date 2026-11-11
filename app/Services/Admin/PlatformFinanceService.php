<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Logistics\LogisticsRemittanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zefanya's own books, read from the per-order snapshots. Revenue is commission
 * only: COD cash, seller payouts and origin shipping shares pass through
 * Zefanya and are never revenue. They are listed as liabilities instead.
 */
class PlatformFinanceService
{
    public function __construct(private readonly LogisticsRemittanceService $remittances = new LogisticsRemittanceService) {}

    /**
     * Revenue and expenses for orders finalized in the Philippine month
     * (or all time when `$month` is false). `$from` picks another month, as
     * the UTC start of that Philippine month.
     *
     * @return array{commissionMinor: int, platformVoucherMinor: int, shippingVoucherMinor: int, netMinor: int}
     */
    public function earnings(bool $month = true, ?Carbon $from = null): array
    {
        $finalized = fn () => SellerOrder::query()
            ->when($month, function ($query) use ($from) {
                $start = $from?->copy() ?? Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc();

                return $query->where('commission_finalized_at', '>=', $start)
                    // Add the month in Manila time: in UTC a month starts on the previous
                    // day, and adding a month to Sep 30 lands on Oct 30, dropping Oct 31.
                    ->when($from !== null, fn ($inner) => $inner->where('commission_finalized_at', '<', $start->copy()->timezone(StoreSale::TIMEZONE)->addMonthNoOverflow()->utc()));
            })
            ->whereNotNull('commission_finalized_at');
        $commission = (int) $finalized()->sum('commission_minor');
        $platform = (int) $finalized()->where('platform_voucher_funding_source', Voucher::FUNDING_MARKETPLACE)->sum('platform_voucher_discount_minor');
        $shipping = (int) $finalized()->where('shipping_voucher_funding_source', Voucher::FUNDING_MARKETPLACE)->sum('shipping_voucher_discount_minor');

        return [
            'commissionMinor' => $commission,
            'platformVoucherMinor' => $platform,
            'shippingVoucherMinor' => $shipping,
            'netMinor' => $commission - $platform - $shipping,
        ];
    }

    /**
     * What Zefanya owes, is owed, or is still waiting on right now. Nothing here
     * is revenue and none of it is subtracted from net earnings.
     *
     * @return array{
     *     sellerPayableMinor: int,
     *     originPayableMinor: int,
     *     destinationPayableMinor: int,
     *     logisticsPayableMinor: int,
     *     refundsToRecordCount: int,
     *     refundsToRecordMinor: int,
     *     refundsRecordedMonthMinor: int,
     *     commissionOnRefundedMinor: int,
     *     sellerDebtMinor: int,
     *     cashWithRidersMinor: int,
     *     cashAtCompaniesMinor: int,
     *     cashSentAwaitingMinor: int,
     *     outstandingCashMinor: int,
     *     totalLiabilitiesMinor: int
     * }
     */
    public function position(): array
    {
        // Same eligibility as the seller's withdrawable balance: finalized, completed and not on hold.
        $sellerPayable = (int) SellerSettlement::query()
            ->where('status', SellerSettlement::PENDING_PAYOUT)->whereNull('paid_at')
            ->whereNull('pending_reason')->whereNotNull('financials_finalized_at')
            ->whereColumn('paid_amount_minor', '<', 'seller_payout_minor')
            ->whereHas('sellerOrder', fn ($query) => $query->where('status', OrderStatus::Completed->value))
            ->sum(DB::raw('seller_payout_minor - paid_amount_minor'));

        $origin = (int) $this->remittances->originOwedByProvider()->sum('amountMinor');
        $destination = (int) $this->remittances->destinationOwed()->sum(fn (CodRemittance $parcel): int => (int) $parcel->destination_share_minor);

        $toRecord = ParcelReturn::query()->where('financial_review_required', true)->whereNull('refunded_at');
        $refundsToRecordMinor = (int) (clone $toRecord)
            ->join('seller_orders', 'seller_orders.id', '=', 'parcel_returns.seller_order_id')
            ->join('payments', 'payments.seller_order_id', '=', 'seller_orders.id')
            ->sum('payments.amount_minor');

        $debts = (int) SellerDebt::query()->whereColumn('settled_minor', '<', 'amount_minor')
            ->sum(DB::raw('amount_minor - settled_minor'));

        $withRiders = (int) CodRemittance::query()->where('status', CodRemittance::PENDING_REMITTANCE)->sum('amount_minor')
            + (int) CodRemittance::query()->where('status', CodRemittance::PARTIALLY_REMITTED)->sum(DB::raw('shortage_minor - shortage_recovered_minor'));
        $atCompanies = (int) $this->remittances->unsentByProvider()->sum('dueMinor');
        $sent = (int) LogisticsRemittance::query()->where('status', LogisticsRemittance::SUBMITTED)->sum('amount_minor');

        return [
            'sellerPayableMinor' => $sellerPayable,
            'originPayableMinor' => $origin,
            'destinationPayableMinor' => $destination,
            'logisticsPayableMinor' => $origin + $destination,
            'refundsToRecordCount' => (clone $toRecord)->count(),
            'refundsToRecordMinor' => $refundsToRecordMinor,
            'refundsRecordedMonthMinor' => (int) ParcelReturn::query()
                ->where('refunded_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc())
                ->sum('refund_amount_minor'),
            'commissionOnRefundedMinor' => (int) SellerOrder::query()->whereNotNull('commission_finalized_at')
                ->whereHas('parcelReturn', fn ($query) => $query->whereNotNull('refunded_at'))
                ->sum('commission_minor'),
            'sellerDebtMinor' => $debts,
            'cashWithRidersMinor' => $withRiders,
            'cashAtCompaniesMinor' => $atCompanies,
            'cashSentAwaitingMinor' => $sent,
            'outstandingCashMinor' => $withRiders + $atCompanies + $sent,
            'totalLiabilitiesMinor' => $sellerPayable + $origin + $destination + $refundsToRecordMinor,
        ];
    }
}
