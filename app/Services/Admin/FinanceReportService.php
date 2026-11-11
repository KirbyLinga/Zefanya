<?php

namespace App\Services\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\OriginSharePayout;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only monthly finance report for the admin Finance page.
 *
 * Earnings reuse PlatformFinanceService so this page and the dashboard never
 * disagree. The ledger lists only money that passes through Zefanya: online
 * payments in, logistics remittances in (COD cash arrives this way, so COD
 * payments are not listed separately), and payouts, logistics shares and
 * refunds out. Rider payouts are paid by logistics companies, not Zefanya.
 */
class FinanceReportService
{
    public const IN = 'in';

    public const OUT = 'out';

    public const OWED_BY_ZEFANYA = 'owed_by_zefanya';

    public const OWED_TO_ZEFANYA = 'owed_to_zefanya';

    /** Ledger entry types and their labels. */
    public const TYPES = [
        'online_payment' => 'Online payment',
        'logistics_remittance' => 'COD remittance received',
        'seller_payout' => 'Seller payout',
        'origin_share' => 'Logistics share paid',
        'return_refund' => 'Return refund',
        'seller_debt' => 'Seller debt',
    ];

    public const DIRECTIONS = [
        self::IN => 'Money in',
        self::OUT => 'Money out',
        self::OWED_BY_ZEFANYA => 'Owed by Zefanya',
        self::OWED_TO_ZEFANYA => 'Owed to Zefanya',
    ];

    public function __construct(private readonly PlatformFinanceService $platform = new PlatformFinanceService) {}

    /** The Philippine month picked as `YYYY-MM`; anything invalid or in the future is this month. */
    public static function period(mixed $month): Carbon
    {
        $current = Carbon::now(StoreSale::TIMEZONE)->startOfMonth();
        if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $current;
        }
        $picked = Carbon::createFromFormat('!Y-m', $month, StoreSale::TIMEZONE);

        return $picked->greaterThan($current) ? $current : $picked;
    }

    /** @return list<array{value: string, label: string}> The last 12 months, newest first. */
    public static function periodOptions(): array
    {
        $start = Carbon::now(StoreSale::TIMEZONE)->startOfMonth();

        return collect(range(0, 11))->map(fn (int $back): array => [
            'value' => $start->copy()->subMonthsNoOverflow($back)->format('Y-m'),
            'label' => $start->copy()->subMonthsNoOverflow($back)->format('F Y'),
        ])->all();
    }

    /**
     * Orders whose commission was finalized in the month, plus platform earnings.
     *
     * @return array{finalizedOrders: int, itemSalesMinor: int, shippingFeesMinor: int, sellerNetMinor: int, commissionMinor: int, platformVoucherMinor: int, shippingVoucherMinor: int, netMinor: int}
     */
    public function summary(Carbon $period): array
    {
        [$start, $end] = $this->bounds($period);
        $finalized = SellerOrder::query()->where('commission_finalized_at', '>=', $start)->where('commission_finalized_at', '<', $end);

        return [
            'finalizedOrders' => (clone $finalized)->count(),
            'itemSalesMinor' => (int) (clone $finalized)->sum('subtotal_minor'),
            'shippingFeesMinor' => (int) (clone $finalized)->sum('shipping_fee_minor'),
            'sellerNetMinor' => (int) (clone $finalized)->sum('seller_net_minor'),
            ...$this->platform->earnings(true, $start),
        ];
    }

    /**
     * Finalized orders in the month by how the buyer paid.
     *
     * @return list<array{method: string, label: string, orders: int, amountMinor: int}>
     */
    public function paymentMethods(Carbon $period): array
    {
        [$start, $end] = $this->bounds($period);

        $rows = Payment::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'payments.seller_order_id')
            ->where('seller_orders.commission_finalized_at', '>=', $start)
            ->where('seller_orders.commission_finalized_at', '<', $end)
            ->groupBy('payments.method')
            ->selectRaw('payments.method as method, count(*) as orders, sum(payments.amount_minor) as amount')
            ->get()->keyBy('method');

        return collect(PaymentMethod::cases())->map(fn (PaymentMethod $method): array => [
            'method' => $method->value,
            'label' => $method->label(),
            'orders' => (int) ($rows[$method->value]->orders ?? 0),
            'amountMinor' => (int) ($rows[$method->value]->amount ?? 0),
        ])->all();
    }

    /**
     * Platform earnings for the last `$months` Philippine months, oldest first.
     *
     * @return list<array{label: string, value: string, commissionMinor: int, voucherMinor: int, netMinor: int}>
     */
    public function trend(Carbon $until, int $months = 12): array
    {
        return collect(range($months - 1, 0))->map(function (int $back) use ($until): array {
            $month = $until->copy()->subMonthsNoOverflow($back);
            $earnings = $this->platform->earnings(true, $month->copy()->utc());

            return [
                'label' => $month->format('M Y'),
                'value' => $month->format('Y-m'),
                'commissionMinor' => $earnings['commissionMinor'],
                'voucherMinor' => $earnings['platformVoucherMinor'] + $earnings['shippingVoucherMinor'],
                'netMinor' => $earnings['netMinor'],
            ];
        })->all();
    }

    /**
     * Every money event in the month, newest first.
     *
     * @return Collection<int, array{at: Carbon, type: string, typeLabel: string, direction: string, directionLabel: string, reference: string, party: string, method: string, amountMinor: int, status: string}>
     */
    public function ledger(Carbon $period): Collection
    {
        [$start, $end] = $this->bounds($period);
        $row = fn (Carbon $at, string $type, string $direction, ?string $reference, ?string $party, ?string $method, int $amount, string $status): array => [
            'at' => $at->copy()->timezone(StoreSale::TIMEZONE),
            'type' => $type,
            'typeLabel' => self::TYPES[$type],
            'direction' => $direction,
            'directionLabel' => self::DIRECTIONS[$direction],
            'reference' => $reference ?: '—',
            'party' => $party ?: '—',
            'method' => $method ?: '—',
            'amountMinor' => $amount,
            'status' => $status,
        ];
        $tracking = fn (?SellerOrder $order): ?string => $order?->tracking_number ?: ($order ? 'Order #'.$order->id : null);

        $rows = collect();

        Payment::query()->with('sellerOrder.seller')
            ->where('status', PaymentStatus::Paid->value)
            ->where('method', '!=', PaymentMethod::Cod->value)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->get()
            ->each(fn (Payment $payment) => $rows->push($row($payment->paid_at, 'online_payment', self::IN, $tracking($payment->sellerOrder),
                $payment->sellerOrder?->seller?->business_name, $payment->method->label(), (int) $payment->amount_minor, 'Paid')));

        LogisticsRemittance::query()->with('provider')
            ->where('status', LogisticsRemittance::CONFIRMED)
            ->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end)
            ->get()
            ->each(fn (LogisticsRemittance $remittance) => $rows->push($row($remittance->confirmed_at, 'logistics_remittance', self::IN, $remittance->reference,
                $remittance->provider?->business_name, 'Bank / e-wallet', (int) ($remittance->received_amount_minor ?? $remittance->amount_minor),
                'Confirmed · '.$remittance->parcel_count.' '.str('parcel')->plural($remittance->parcel_count))));

        SellerPayoutRequest::query()->with('seller')
            ->where('status', SellerPayoutRequest::PAID)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->get()
            ->each(fn (SellerPayoutRequest $payout) => $rows->push($row($payout->paid_at, 'seller_payout', self::OUT, $payout->payout_reference,
                $payout->seller?->business_name, $payout->payout_destination ? 'Transfer' : null, (int) $payout->amount_minor - (int) $payout->debt_deducted_minor,
                (int) $payout->debt_deducted_minor > 0 ? 'Paid · debt deducted' : 'Paid')));

        OriginSharePayout::query()->with(['provider', 'reversal'])
            ->where('paid_on', '>=', $period->toDateString())
            ->where('paid_on', '<', $period->copy()->addMonthNoOverflow()->toDateString())
            ->get()
            ->each(fn (OriginSharePayout $payout) => $rows->push($row(Carbon::parse($payout->paid_on->toDateString(), StoreSale::TIMEZONE), 'origin_share', self::OUT, $payout->reference,
                $payout->provider?->business_name, 'Transfer', $payout->reversal ? 0 : (int) $payout->amount_minor,
                $payout->reversal ? 'Reversed (was '.$this->peso((int) $payout->amount_minor).')' : 'Paid · '.$payout->parcel_count.' '.str('parcel')->plural($payout->parcel_count))));

        ParcelReturn::query()->with('sellerOrder.seller')
            ->where('refunded_at', '>=', $start)->where('refunded_at', '<', $end)
            ->get()
            ->each(fn (ParcelReturn $return) => $rows->push($row($return->refunded_at, 'return_refund', self::OUT, $tracking($return->sellerOrder),
                'Buyer', 'Refund', (int) $return->refund_amount_minor, 'Recorded')));

        SellerDebt::query()->with(['seller', 'sellerOrder'])
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get()
            ->each(fn (SellerDebt $debt) => $rows->push($row($debt->created_at, 'seller_debt', self::OWED_TO_ZEFANYA, $tracking($debt->sellerOrder),
                $debt->seller?->business_name, null, (int) $debt->amount_minor,
                (int) $debt->settled_minor >= (int) $debt->amount_minor ? 'Settled' : 'Open · '.$this->peso((int) $debt->amount_minor - (int) $debt->settled_minor).' left')));

        return $rows->sortByDesc(fn (array $entry): int => $entry['at']->getTimestamp())->values();
    }

    /**
     * @param  Collection<int, array{direction: string, amountMinor: int}>  $ledger
     * @return array<string, int> Total per direction.
     */
    public function totals(Collection $ledger): array
    {
        return collect(array_keys(self::DIRECTIONS))
            ->mapWithKeys(fn (string $direction): array => [$direction => (int) $ledger->where('direction', $direction)->sum('amountMinor')])
            ->all();
    }

    public function peso(int $minor): string
    {
        return ($minor < 0 ? '-' : '').'₱'.number_format(abs($minor) / 100, 2);
    }

    /** @return array{0: Carbon, 1: Carbon} UTC start and end of the Philippine month. */
    private function bounds(Carbon $period): array
    {
        $start = $period->copy()->timezone(StoreSale::TIMEZONE)->startOfMonth();

        return [$start->copy()->utc(), $start->copy()->addMonthNoOverflow()->utc()];
    }
}
