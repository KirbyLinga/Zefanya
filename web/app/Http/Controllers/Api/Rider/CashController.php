<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Logistics\CodRemittance;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/rider/cod — the rider's COD cash, read-only.
 *
 * The bearer-token twin of the cash half of the Rider web Money page: one
 * CodRemittance per COD delivery is created by the server when the parcel is
 * delivered, and only Logistics confirms receiving it. The app never creates,
 * confirms or recomputes any of it; every figure is RiderCashService's.
 */
class CashController extends Controller
{
    use ApiResponses;

    public function index(Request $request, RiderOperationsService $operations, RiderCashService $cash): JsonResponse
    {
        // Same access as the web Money page, which a wind-down rider keeps.
        $courier = $operations->operationalCourier($request->user(), allowWindDown: true);
        $summary = $cash->summaryFor($courier);
        $cutoff = $cash->overdueCutoff();
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        $holding = $cash->holdingFor($courier);
        $handedOver = $cash->handedOverFor($courier, $limit);

        return $this->success('COD cash retrieved.', [
            'summary' => [
                // Collected = still held + confirmed handed over (amounts as collected).
                'collected_minor' => $summary['holdingMinor'] + (int) CodRemittance::query()
                    ->where('courier_id', $courier->getKey())
                    ->where('status', CodRemittance::REMITTED_TO_LOGISTICS)
                    ->sum('amount_minor'),
                'holding_minor' => $summary['holdingMinor'],
                'holding_count' => $summary['holdingCount'],
                'overdue_count' => $summary['overdueCount'],
                'overdue_after_days' => LogisticsRemittanceService::OVERDUE_AFTER_DAYS,
                'oldest_holding_at' => $summary['oldestAt']?->toIso8601String(),
                'remitted_minor' => $summary['handedOverMinor'],
                'remitted_month_minor' => $summary['handedOverMonthMinor'],
            ],
            'holding' => $holding->map(fn (CodRemittance $row): array => [
                ...$this->row($row),
                'overdue' => $row->created_at !== null && $row->created_at->lt($cutoff),
            ])->values(),
            'remitted' => collect($handedOver->items())->map(fn (CodRemittance $row): array => $this->row($row))->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(CodRemittance $row): array
    {
        return [
            'id' => $row->id,
            'seller_order_id' => $row->seller_order_id,
            'tracking_number' => $row->sellerOrder?->tracking_number,
            'status' => $row->status,
            'amount_minor' => (int) $row->amount_minor,
            'received_amount_minor' => $row->received_amount_minor === null ? null : (int) $row->received_amount_minor,
            'collected_at' => $row->created_at?->toIso8601String(),
            'confirmed_at' => $row->confirmed_at?->toIso8601String(),
        ];
    }
}
