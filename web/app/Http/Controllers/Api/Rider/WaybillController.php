<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderOperationsService;
use App\Services\Rider\RiderWaybillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/rider/waybills/{sellerOrder} — the private digital waybill.
 *
 * Same rule as the Rider web page (RiderWaybillService): only the rider's own
 * pickup or delivery, seller contact on a pickup and buyer contact on a
 * delivery. Never cached.
 */
class WaybillController extends Controller
{
    use ApiResponses;

    public function show(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderWaybillService $waybills,
    ): JsonResponse {
        $courier = $operations->operationalCourier($request->user(), allowWindDown: true);
        $context = $waybills->context($courier, $sellerOrder);

        return $this->success('Waybill retrieved.', $waybills->present($sellerOrder, $context))
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
