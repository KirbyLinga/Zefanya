<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ShipmentResource;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerShipmentConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $shipments = SellerOrder::query()
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $request->user()->getKey()))
            ->with(['seller', 'orderItems.product', 'payment'])
            ->latest('id')->paginate(20);

        return $this->success('Shipments retrieved.', ShipmentResource::collection($shipments)->response()->getData(true));
    }

    public function show(Request $request, SellerOrder $shipment): JsonResponse
    {
        abort_unless((int) $shipment->order->buyer_id === (int) $request->user()->getKey(), 404);

        return $this->success('Shipment retrieved.', new ShipmentResource($shipment->load(['seller', 'orderItems.product', 'payment'])));
    }

    public function confirm(Request $request, Order $order, SellerOrder $shipment, BuyerShipmentConfirmation $confirmation): JsonResponse
    {
        abort_unless((int) $order->buyer_id === (int) $request->user()->getKey(), 404);
        abort_unless((int) $shipment->order_id === (int) $order->getKey(), 404);
        $confirmation->confirm($order, $request->user(), (int) $shipment->getKey());

        return $this->success('Shipment receipt confirmed.', new ShipmentResource($shipment->fresh()->load(['seller', 'orderItems.product', 'payment'])));
    }
}
