<?php

namespace App\Services\Rider;

use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsRemittanceService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Cash a rider collects from buyers at the door. The rider holds it until the
 * Logistics company confirms receiving it; the rider cannot confirm it themself.
 */
class RiderCashService
{
    /** @return array{holdingMinor: int, holdingCount: int, overdueCount: int, oldestAt: ?Carbon, handedOverMinor: int, handedOverMonthMinor: int} */
    public function summaryFor(Courier $courier): array
    {
        $holding = CodRemittance::query()->where('courier_id', $courier->getKey())
            ->where('status', CodRemittance::PENDING_REMITTANCE);
        $handedOver = CodRemittance::query()->where('courier_id', $courier->getKey())
            ->where('status', CodRemittance::REMITTED_TO_LOGISTICS);
        $oldest = (clone $holding)->min('created_at');

        return [
            'holdingMinor' => (int) (clone $holding)->sum('amount_minor'),
            'holdingCount' => (clone $holding)->count(),
            'overdueCount' => (clone $holding)->where('created_at', '<', $this->overdueCutoff())->count(),
            'oldestAt' => $oldest === null ? null : Carbon::parse($oldest),
            'handedOverMinor' => (int) (clone $handedOver)->sum('received_amount_minor'),
            'handedOverMonthMinor' => (int) (clone $handedOver)
                ->where('confirmed_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc())
                ->sum('received_amount_minor'),
        ];
    }

    /** Cash still with the rider, oldest first. @return Collection<int, CodRemittance> */
    public function holdingFor(Courier $courier): Collection
    {
        return CodRemittance::query()->where('courier_id', $courier->getKey())
            ->where('status', CodRemittance::PENDING_REMITTANCE)
            ->with('sellerOrder:id,tracking_number')
            ->orderBy('id')->get();
    }

    public function handedOverFor(Courier $courier, int $perPage = 10): LengthAwarePaginator
    {
        return CodRemittance::query()->where('courier_id', $courier->getKey())
            ->where('status', CodRemittance::REMITTED_TO_LOGISTICS)
            ->with('sellerOrder:id,tracking_number')
            ->latest('confirmed_at')->latest('id')
            ->paginate($perPage, ['*'], 'handed_page')->withQueryString();
    }

    public function overdueCutoff(): Carbon
    {
        return now()->subDays(LogisticsRemittanceService::OVERDUE_AFTER_DAYS);
    }
}
