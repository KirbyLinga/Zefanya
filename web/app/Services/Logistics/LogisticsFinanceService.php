<?php

namespace App\Services\Logistics;

use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\RiderEarning;
use Illuminate\Support\Facades\DB;

/**
 * A Logistics company's own profit and loss. Shipping earnings come from the
 * frozen per-parcel split (kept fee as the delivering company, 40% as the
 * origin carrier). Rider pay is the company's expense; it never reduces the
 * cash the company must remit to Zefanya.
 */
class LogisticsFinanceService
{
    public function __construct(private readonly LogisticsRemittanceService $remittances = new LogisticsRemittanceService) {}

    /**
     * @return array{
     *     deliveredFeeMinor: int,
     *     originShareMinor: int,
     *     grossMinor: int,
     *     riderPickupMinor: int,
     *     riderDeliveryMinor: int,
     *     riderExpenseMinor: int,
     *     riderPaidMinor: int,
     *     riderUnpaidMinor: int,
     *     netMinor: int,
     *     originReceivedMinor: int,
     *     originPaidMinor: int,
     *     originAwaitingZefanyaMinor: int,
     *     originReadyMinor: int
     * }
     */
    public function statement(LogisticsProvider $provider): array
    {
        $delivered = (int) CodRemittance::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->where('cod_remittances.logistics_provider_id', $provider->getKey())
            ->sum(DB::raw(CodRemittance::KEPT_SHIPPING_SQL));

        $origin = $this->remittances->originShares($provider);

        $earnings = RiderEarning::query()->where('logistics_provider_id', $provider->getKey());
        $pickup = (int) (clone $earnings)->where('job', RiderEarning::PICKUP)->sum('amount_minor');
        $delivery = (int) (clone $earnings)->where('job', RiderEarning::DELIVERY)->sum('amount_minor');
        $unpaid = (int) (clone $earnings)->whereNull('rider_payout_id')->sum('amount_minor');

        $gross = $delivered + $origin['shareMinor'];
        $expense = $pickup + $delivery;

        return [
            'deliveredFeeMinor' => $delivered,
            'originShareMinor' => $origin['shareMinor'],
            'grossMinor' => $gross,
            'riderPickupMinor' => $pickup,
            'riderDeliveryMinor' => $delivery,
            'riderExpenseMinor' => $expense,
            'riderPaidMinor' => $expense - $unpaid,
            'riderUnpaidMinor' => $unpaid,
            'netMinor' => $gross - $expense,
            'originReceivedMinor' => $origin['receivedMinor'],
            'originPaidMinor' => $origin['paidMinor'],
            'originAwaitingZefanyaMinor' => $origin['shareMinor'] - $origin['receivedMinor'],
            'originReadyMinor' => $origin['receivedMinor'] - $origin['paidMinor'],
        ];
    }
}
