<?php

namespace App\Http\Controllers\Rider;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderOperationsService;
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
    ): View|Response {
        $rider = $request->user('rider');
        $courier = $operations->operationalCourier($rider);

        $sellerOrder->load([
            'order.buyer',
            'seller',
            'orderItems',
            'payment',
        ]);

        $isPickup = (int) $sellerOrder->courier_id === (int) $courier->id
            && in_array($sellerOrder->status, [
                OrderStatus::ReadyForPickup,
                OrderStatus::PickedUp,
            ], true);

        $isDelivery = (int) $sellerOrder->delivery_courier_id === (int) $courier->id
            && in_array($sellerOrder->status, [
                OrderStatus::AssignedToRider,
                OrderStatus::OutForDelivery,
                OrderStatus::DeliveryFailed,
            ], true);

        abort_unless($isPickup || $isDelivery, 403, 'This waybill is not available for your assignment.');

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
