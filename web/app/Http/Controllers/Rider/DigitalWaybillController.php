<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderOperationsService;
use App\Services\Rider\RiderWaybillService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Private, assignment-scoped digital waybill for the operational Rider.
 * Not a public URL — session auth + courier ownership required.
 */
class DigitalWaybillController extends Controller
{
    public function show(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderWaybillService $waybills,
    ): View|Response {
        $rider = $request->user('rider');
        $courier = $operations->operationalCourier($rider, allowWindDown: true);

        $sellerOrder->load([
            'order.buyer',
            'seller',
            'orderItems',
            'payment',
        ]);

        $context = $waybills->context($courier, $sellerOrder);
        $isPickup = $context === 'pickup';
        $isDelivery = ! $isPickup;

        $view = view('Rider.Waybills.show', [
            'sellerOrder' => $sellerOrder,
            'showSellerContact' => $isPickup,
            'showBuyerContact' => $isDelivery,
            'context' => $isPickup ? 'pickup' : 'delivery',
        ]);

        return response($view)
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
