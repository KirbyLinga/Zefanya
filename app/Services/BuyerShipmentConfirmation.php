<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuyerShipmentConfirmation
{
    public function __construct(private SellerOrderStatusService $statuses) {}

    public function confirm(Order $order, Buyer $buyer, ?int $shipmentId = null): void
    {
        abort_unless($order->buyer_id === $buyer->id, 404);
        DB::transaction(function () use ($order, $buyer, $shipmentId): void {
            $shipments = $order->sellerOrders()
                ->when($shipmentId !== null, fn ($query) => $query->whereKey($shipmentId))
                ->orderBy('id')->lockForUpdate()->get();
            if ($shipmentId !== null && $shipments->isEmpty()) {
                abort(404);
            }
            $delivered = $shipments->where('status', OrderStatus::Delivered);
            if ($delivered->isEmpty()) {
                if ($shipments->isNotEmpty() && $shipments->every(fn ($shipment) => $shipment->status === OrderStatus::Completed)) {
                    return;
                }
                throw ValidationException::withMessages(['shipment' => 'There are no delivered shipments to confirm.']);
            }
            foreach ($delivered as $shipment) {
                $this->statuses->transition($shipment, OrderStatus::Completed,
                    note: 'Confirmed received by buyer.', changedBy: $buyer->fullName(),
                    actor: OrderActor::Buyer, actorId: $buyer->id, source: 'web');
            }
        }, 3);
    }
}
