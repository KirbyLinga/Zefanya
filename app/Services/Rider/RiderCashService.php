<?php

namespace App\Services\Rider;

use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsRemittanceService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            ->whereIn('status', [CodRemittance::PENDING_REMITTANCE, CodRemittance::PARTIALLY_REMITTED]);
        $handedOver = CodRemittance::query()->where('courier_id', $courier->getKey())
            ->whereIn('status', [CodRemittance::REMITTED_TO_LOGISTICS, CodRemittance::PARTIALLY_REMITTED]);
        $oldest = (clone $holding)->min('created_at');

        return [
            'holdingMinor' => (int) (clone $holding)->sum(DB::raw('CASE WHEN status = \''.CodRemittance::PARTIALLY_REMITTED.'\' THEN shortage_minor - shortage_recovered_minor ELSE amount_minor END')),
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
            ->whereIn('status', [CodRemittance::PENDING_REMITTANCE, CodRemittance::PARTIALLY_REMITTED])
            ->with('sellerOrder:id,tracking_number')
            ->orderBy('id')->get();
    }

    public function handedOverFor(Courier $courier, int $perPage = 10): LengthAwarePaginator
    {
        return CodRemittance::query()->where('courier_id', $courier->getKey())
            ->whereIn('status', [CodRemittance::REMITTED_TO_LOGISTICS, CodRemittance::PARTIALLY_REMITTED])
            ->with('sellerOrder:id,tracking_number')
            ->latest('confirmed_at')->latest('id')
            ->paginate($perPage, ['*'], 'handed_page')->withQueryString();
    }

    public function overdueCutoff(): Carbon
    {
        return now()->subDays(LogisticsRemittanceService::OVERDUE_AFTER_DAYS);
    }
}
