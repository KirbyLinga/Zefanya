<?php

namespace App\Services\Rider;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Support\RuntimeSchema;
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
        $monthStart = Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc();
        // Initial receipt and later recoveries are distinct cash movements.
        // Read the immutable history rather than date the cumulative balance
        // using confirmed_at (or the timestamp of only the latest recovery).
        $recoveries = OperationsAuditLog::query()
            ->where('entity_type', 'cod_remittance')->where('action', 'cod_shortage_recovered')
            ->whereIn('entity_id', CodRemittance::query()->where('courier_id', $courier->getKey())->select('id'))
            ->where('created_at', '>=', $monthStart)->get()
            ->sum(function (OperationsAuditLog $log): int {
                if (isset($log->new_values['recovered_amount_minor'])) {
                    return (int) $log->new_values['recovered_amount_minor'];
                }
                // Older audits already store exact before/after values formatted
                // as pesos with two decimals. Decode without binary float math.
                $minor = static function (string $value): int {
                    $decimal = preg_replace('/[^0-9.]/', '', $value);
                    [$whole, $cents] = explode('.', $decimal);

                    return (int) $whole * 100 + (int) $cents;
                };

                return $minor($log->new_values['received']) - $minor($log->old_values['received']);
            });

        return [
            'holdingMinor' => (int) (clone $holding)->sum(DB::raw('CASE WHEN status = \''.CodRemittance::PARTIALLY_REMITTED.'\' THEN shortage_minor - shortage_recovered_minor ELSE amount_minor END')),
            'holdingCount' => (clone $holding)->count(),
            'overdueCount' => (clone $holding)->where('created_at', '<', $this->overdueCutoff())->count(),
            'oldestAt' => $oldest === null ? null : Carbon::parse($oldest),
            'handedOverMinor' => (int) (clone $handedOver)->sum('received_amount_minor'),
            'handedOverMonthMinor' => (int) (clone $handedOver)
                ->where('confirmed_at', '>=', $monthStart)
                ->sum(DB::raw('received_amount_minor - shortage_recovered_minor')) + (int) $recoveries,
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

    /** Whether the handover-signal column has been migrated (the page degrades without it). */
    public function handoverSignalAvailable(): bool
    {
        return RuntimeSchema::hasColumn('cod_remittances', 'rider_handover_declared_at');
    }

    /**
     * The rider marks cash as handed over to their Logistics company.
     *
     * This is only a signal. It never changes the remittance status, amounts, settlements or
     * pay: Logistics still confirms the exact amount it physically received, and only that
     * confirmation moves the money. Only the rider's own, still-pending, unmarked rows change.
     *
     * @param  array<int, int>  $remittanceIds
     */
    public function declareHandover(Courier $courier, array $remittanceIds): int
    {
        if (! $this->handoverSignalAvailable() || $remittanceIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($courier, $remittanceIds): int {
            $rows = CodRemittance::query()
                ->where('courier_id', $courier->getKey())
                ->whereIn('id', array_values(array_unique(array_map('intval', $remittanceIds))))
                ->where('status', CodRemittance::PENDING_REMITTANCE)
                ->whereNull('rider_handover_declared_at')
                ->lockForUpdate()->get();

            foreach ($rows as $row) {
                $row->forceFill(['rider_handover_declared_at' => now()])->save();
            }

            return $rows->count();
        }, 3);
    }

    public function overdueCutoff(): Carbon
    {
        return now()->subDays(LogisticsRemittanceService::OVERDUE_AFTER_DAYS);
    }
}
