<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider mobile return pickups: collecting an approved return from the buyer.
 *
 * Bearer-token twins of the Rider web Return Pickups page. Both read
 * ParcelReturnService::pendingBuyerCollectionsFor and collect through
 * ParcelReturnService::confirmBuyerCollection, which locks the parcel, checks
 * the assigned rider, verifies the parcel's tracking number, refuses a second
 * collection (409) and moves custody to the rider's station. Refund and
 * restock decisions stay with the later seller-receipt step; nothing here
 * changes them.
 */
class ReturnPickupController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly RiderOperationsService $operations,
        private readonly ParcelReturnService $returns,
    ) {}

    /** GET /api/rider/return-pickups */
    public function index(Request $request): JsonResponse
    {
        $rider = $request->user();
        $this->operations->operationalCourier($rider);

        return $this->success('Return pickups retrieved.', [
            'returns' => $this->returns->pendingBuyerCollectionsFor($rider)
                ->map(fn (ParcelReturn $record): array => $this->present($record))
                ->values(),
        ]);
    }

    /** POST /api/rider/return-pickups/{parcelReturn}/collect — {tracking_number}. */
    public function collect(Request $request, ParcelReturn $parcelReturn): JsonResponse
    {
        $rider = $request->user();
        $this->operations->operationalCourier($rider);
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:64']]);

        $this->returns->confirmBuyerCollection($parcelReturn, $rider, $data['tracking_number']);

        $record = $parcelReturn->fresh(['sellerOrder.order.buyer', 'sellerOrder.orderItems']);

        return $this->success('Parcel collected. Bring it to your Station Hub.', [
            ...$this->present($record),
            'station_hub_id' => $rider->station_hub_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ParcelReturn $record): array
    {
        $parcel = $record->sellerOrder;
        $address = $parcel?->order?->shipping_address ?? [];

        return [
            'id' => $record->id,
            'seller_order_id' => $record->seller_order_id,
            'tracking_number' => $parcel?->tracking_number,
            'status' => $record->status?->value ?? (string) $record->status,
            'reason' => $record->reason,
            'buyer_name' => $parcel?->order?->buyer?->fullName() ?? ($address['name'] ?? null),
            'buyer_phone' => $address['phone'] ?? null,
            'pickup_address' => $address['full'] ?? null,
            'item_count' => (int) ($parcel?->orderItems->sum('quantity') ?? 0),
            'assigned_at' => $record->collection_assigned_at?->toIso8601String(),
            'collected_at' => $record->collected_at?->toIso8601String(),
        ];
    }
}
