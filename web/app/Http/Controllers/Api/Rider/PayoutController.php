<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Rider\RiderPayout;
use App\Services\Rider\RiderEarningService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/rider/payouts — what Logistics actually paid this rider, read-only.
 *
 * The bearer-token twin of the pay half of the Rider web Money page: the pay
 * summary from RiderEarningService and the RiderPayout rows Logistics
 * recorded, with a payout reversed for correction flagged as such.
 */
class PayoutController extends Controller
{
    use ApiResponses;

    public function index(Request $request, RiderOperationsService $operations, RiderEarningService $pay): JsonResponse
    {
        // Same access as the web Money page, which a wind-down rider keeps.
        $courier = $operations->operationalCourier($request->user(), allowWindDown: true);
        $summary = $pay->summaryFor($courier);
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        $payouts = RiderPayout::query()->where('courier_id', $courier->getKey())
            ->with('reversal')->latest('id')->limit($limit)->get();

        return $this->success('Payouts retrieved.', [
            'summary' => [
                'earned_month_minor' => $summary['monthMinor'],
                'paid_minor' => $summary['paidMinor'],
                'pending_minor' => $summary['pendingMinor'],
            ],
            'payouts' => $payouts->map(fn (RiderPayout $payout): array => [
                'id' => $payout->id,
                'reference' => $payout->reference,
                'paid_on' => $payout->paid_on?->toDateString(),
                'earning_count' => (int) $payout->earning_count,
                'amount_minor' => (int) $payout->amount_minor,
                'status' => $payout->status,
                'reversed' => $payout->reversal !== null,
            ])->values(),
        ]);
    }
}
