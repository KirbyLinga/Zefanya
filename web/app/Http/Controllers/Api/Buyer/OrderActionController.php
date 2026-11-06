<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ShipmentResource;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerOrderCancellation;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Buyer mobile orders: list/detail with per-seller shipments and tracking,
 * cancellation before seller acceptance, and returns.
 *
 * Authorization is BuyerOrderPolicy (as on the web); the work is done by
 * BuyerOrderCancellation and ParcelReturnService with the web's validation
 * rules and messages.
 */
class OrderActionController extends Controller
{
    use ApiResponses;

    private const SHIPMENT_RELATIONS = ['seller', 'orderItems.product', 'payment', 'parcelReturn'];

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('buyer_id', $request->user()->getKey())
            ->with(['sellerOrders' => fn ($q) => $q->with(self::SHIPMENT_RELATIONS)])
            ->latest('id')
            ->paginate(15);

        return $this->success('Orders retrieved.', [
            'data' => $orders->getCollection()->map(fn (Order $order) => $this->present($order, false))->values(),
            'meta' => [
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'has_more' => $orders->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, Order $order, ParcelReturnService $returns): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'view', $order);
        $order->load(['sellerOrders' => fn ($q) => $q->with([...self::SHIPMENT_RELATIONS, 'statusHistories'])]);

        $data = $this->present($order, true);
        $data['returnable_shipment_ids'] = $order->sellerOrders->filter(fn (SellerOrder $parcel) => $returns->canBuyerRequest($parcel))->modelKeys();
        $data['return_reasons'] = ParcelReturnService::BUYER_REASONS;

        return $this->success('Order retrieved.', $data);
    }

    public function cancel(Request $request, Order $order, SellerOrder $shipment, BuyerOrderCancellation $cancellation): JsonResponse
    {
        $buyer = $request->user();
        $this->authorizeForUser($buyer, 'cancelSellerOrder', $order);
        abort_unless((int) $shipment->order_id === (int) $order->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [
            'reason.required' => 'Please provide a cancellation reason.',
        ]);

        try {
            $cancellation->cancel($order, $shipment, $buyer, $data['reason']);
        } catch (StaleStateException $e) {
            $status = $shipment->fresh()->status;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['status' => $status->value, 'status_label' => $status->label()],
                'errors' => ['status' => [$e->getMessage()]],
            ], 409);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), ['reason' => [$e->getMessage()]]);
        }

        return $this->success('Shipment cancelled. The seller has been notified.', new ShipmentResource($shipment->fresh()->load(self::SHIPMENT_RELATIONS)));
    }

    public function requestReturn(Request $request, Order $order, SellerOrder $shipment, ParcelReturnService $returns): JsonResponse
    {
        $buyer = $request->user();
        $this->authorizeForUser($buyer, 'view', $order);
        abort_unless((int) $shipment->order_id === (int) $order->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(ParcelReturnService::BUYER_REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:500'],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'description.required' => 'Describe what happened in 10 to 500 characters.',
            'description.min' => 'Describe what happened in 10 to 500 characters.',
            'description.max' => 'Describe what happened in 10 to 500 characters.',
            'photos.required' => 'Add 1 to 5 JPG or PNG photos, up to 5 MB each.',
            'photos.min' => 'Add 1 to 5 JPG or PNG photos, up to 5 MB each.',
            'photos.max' => 'Add up to 5 photos.',
            'photos.*.image' => 'Photos must be JPG or PNG files.',
            'photos.*.mimes' => 'Photos must be JPG or PNG files.',
            'photos.*.max' => 'Each photo may not be larger than 5 MB.',
        ]);

        $record = $returns->requestByBuyer($shipment, $buyer, $data['reason'], $data['description'], $request->file('photos', []));

        return $this->success('Return request recorded. The Seller has been notified.', [
            'id' => $record->id,
            'status' => $record->status->value,
            'reason' => $record->reason,
        ], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function escalateReturn(Request $request, Order $order, SellerOrder $shipment, ParcelReturnService $returns): JsonResponse
    {
        $buyer = $request->user();
        $this->authorizeForUser($buyer, 'view', $order);
        abort_unless((int) $shipment->order_id === (int) $order->id, 404);
        abort_unless($shipment->parcelReturn !== null, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']], [
            'reason.required' => 'Tell the admin why this rejection should be reviewed.',
            'reason.min' => 'Tell the admin why this rejection should be reviewed, in 10 to 1000 characters.',
            'reason.max' => 'Tell the admin why this rejection should be reviewed, in 10 to 1000 characters.',
        ]);

        $returns->escalateByBuyer($shipment->parcelReturn, $buyer, $data['reason']);

        return $this->success('An admin will review this rejection. The seller cannot change it until then.');
    }

    /** @return array<string, mixed> */
    private function present(Order $order, bool $withTracking): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status?->value,
            'status_label' => $order->status?->label(),
            'subtotal_minor' => (int) $order->subtotal_minor,
            'discount_minor' => (int) $order->discount_minor,
            'shipping_discount_minor' => (int) $order->shipping_discount_minor,
            'total_minor' => (int) $order->total_minor,
            'can_confirm_receipt' => $order->canBeConfirmed(),
            'shipping_address' => $withTracking ? $order->shipping_address : null,
            'created_at' => $order->created_at?->toIso8601String(),
            'shipments' => $order->sellerOrders->map(function (SellerOrder $shipment) use ($withTracking): array {
                $row = (new ShipmentResource($shipment))->resolve();
                $row['return_status'] = $shipment->parcelReturn?->status?->value;
                $row['can_cancel'] = $shipment->status === OrderStatus::Placed;
                if ($withTracking) {
                    $row['tracking'] = app(RouteExecutionService::class)->trackingSummary($shipment);
                    $row['history'] = $shipment->statusHistories->map(fn ($h): array => [
                        'status' => $h->to_status?->value,
                        'status_label' => $h->to_status?->label(),
                        'at' => $h->created_at?->toIso8601String(),
                    ])->values();
                }

                return $row;
            })->values(),
        ];
    }
}
