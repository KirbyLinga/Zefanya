<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The seller dashboard's daily sales, in Philippine days. A sale belongs to the
 * day it was first delivered, so later updates to the row cannot move it.
 */
class SellerSalesTrend
{
    public const DAYS = 7;

    /**
     * @return array{
     *     days: Collection<int, array{label: string, date: string, value: int, orders: int, isToday: bool}>,
     *     totalMinor: int,
     *     orderCount: int,
     *     previousMinor: int,
     *     changePercent: ?int,
     *     bestDay: ?array{label: string, date: string, value: int, orders: int, isToday: bool},
     *     ticks: list<int>,
     *     axisMaxMinor: int,
     * }
     */
    public function forSeller(int $sellerId): array
    {
        $timezone = StoreSale::TIMEZONE;
        $today = Carbon::now($timezone)->startOfDay();
        $sold = $this->soldSince($sellerId, $today->copy()->subDays((self::DAYS * 2) - 1));

        $days = collect(range(self::DAYS - 1, 0))->map(function (int $daysAgo) use ($today, $sold): array {
            $day = $today->copy()->subDays($daysAgo);
            $sales = $sold->get($day->toDateString(), collect());

            return [
                'label' => $daysAgo === 0 ? 'Today' : $day->format('D'),
                'date' => $day->format('M j'),
                'value' => (int) $sales->sum(),
                'orders' => $sales->count(),
                'isToday' => $daysAgo === 0,
            ];
        })->values();

        $previousMinor = (int) collect(range(self::DAYS * 2 - 1, self::DAYS))
            ->sum(fn (int $daysAgo): int => (int) $sold->get($today->copy()->subDays($daysAgo)->toDateString(), collect())->sum());

        $totalMinor = (int) $days->sum('value');
        $ticks = $this->ticks((int) $days->max('value'));

        return [
            'days' => $days,
            'totalMinor' => $totalMinor,
            'orderCount' => (int) $days->sum('orders'),
            'previousMinor' => $previousMinor,
            'changePercent' => $previousMinor > 0 ? (int) round(($totalMinor - $previousMinor) / $previousMinor * 100) : null,
            'bestDay' => $totalMinor > 0 ? $days->sortByDesc('value')->first() : null,
            'ticks' => $ticks,
            'axisMaxMinor' => (int) end($ticks),
        ];
    }

    /** Short peso amounts for the axis and bar labels: ₱500, ₱1.5K, ₱2M. */
    public static function compactPeso(int $minor): string
    {
        $pesos = $minor / 100;

        foreach ([1_000_000 => 'M', 1_000 => 'K'] as $size => $suffix) {
            if ($pesos >= $size) {
                return '₱'.rtrim(rtrim(number_format($pesos / $size, 1), '0'), '.').$suffix;
            }
        }

        return '₱'.number_format($pesos);
    }

    /**
     * Subtotals by Philippine date. Delivered and Completed orders, matching the
     * dashboard's Total sales card; rows with no delivery history use updated_at.
     *
     * @return Collection<string, Collection<int, int>>
     */
    private function soldSince(int $sellerId, Carbon $start): Collection
    {
        $firstDelivered = StatusHistory::query()
            ->select('seller_order_id')
            ->selectRaw('MIN(created_at) as delivered_at')
            ->where('to_status', OrderStatus::Delivered->value)
            ->groupBy('seller_order_id');
        $soldAt = 'COALESCE(first_delivered.delivered_at, seller_orders.updated_at)';

        return SellerOrder::query()
            ->leftJoinSub($firstDelivered, 'first_delivered', 'first_delivered.seller_order_id', '=', 'seller_orders.id')
            ->where('seller_orders.seller_id', $sellerId)
            ->whereIn('seller_orders.status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->whereRaw("{$soldAt} >= ?", [$start->copy()->utc()->toDateTimeString()])
            ->selectRaw("seller_orders.subtotal_minor as subtotal_minor, {$soldAt} as sold_at")
            ->toBase()
            ->get()
            ->groupBy(fn (object $row): string => Carbon::parse($row->sold_at, 'UTC')->setTimezone(StoreSale::TIMEZONE)->toDateString())
            ->map(fn (Collection $rows): Collection => $rows->map(fn (object $row): int => (int) $row->subtotal_minor));
    }

    /**
     * Round axis values from zero that reach the tallest bar.
     *
     * @return list<int>
     */
    private function ticks(int $maxMinor): array
    {
        if ($maxMinor <= 0) {
            return [0];
        }

        $rawStep = max(1, $maxMinor / 100 / 4);
        $magnitude = 10 ** floor(log10($rawStep));
        $step = 10 * $magnitude;

        foreach ([1, 2, 2.5, 5] as $nice) {
            if ($nice * $magnitude >= $rawStep) {
                $step = $nice * $magnitude;
                break;
            }
        }

        $stepMinor = (int) round($step * 100);
        $count = (int) ceil($maxMinor / $stepMinor);

        return array_map(fn (int $tick): int => $tick * $stepMinor, range(0, $count));
    }
}
