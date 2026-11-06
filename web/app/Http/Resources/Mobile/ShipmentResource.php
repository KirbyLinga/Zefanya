<?php

namespace App\Http\Resources\Mobile;

use App\Enums\OrderStatus;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\DeliveryOtpService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One per-seller shipment as the Buyer mobile app sees it.
 *
 * Stored amounts only (minor units) — nothing is recomputed here. Seller-
 * private figures (commission, seller net, funding split) are omitted. The
 * active delivery code is the buyer's own, exactly as the buyer web order
 * page shows it while the parcel is out for delivery.
 *
 * @mixin SellerOrder
 */
class ShipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SellerOrder $shipment */
        $shipment = $this->resource;
        $payment = $shipment->payment;
        $code = app(DeliveryOtpService::class)->activeCodeFor($shipment);

        return [
            'id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'seller' => [
                'id' => $shipment->seller_id,
                'name' => $shipment->seller?->business_name ?? $shipment->seller?->fullName(),
            ],
            'items' => $shipment->orderItems->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name ?? $item->product?->name,
                'variant_options' => $item->variant_options,
                'quantity' => (int) $item->quantity,
                'price_minor' => (int) $item->price_minor,
            ])->values(),
            'subtotal_minor' => (int) $shipment->subtotal_minor,
            'shipping_fee_minor' => (int) $shipment->shipping_fee_minor,
            'voucher_discount_minor' => (int) $shipment->voucher_discount_minor,
            'shipping_discount_minor' => (int) $shipment->shipping_discount_minor,
            'payment' => $payment === null ? null : [
                'method' => $payment->method?->value,
                'status' => $payment->status?->value,
                'amount_minor' => (int) $payment->amount_minor,
            ],
            'delivery_code' => $code === null ? null : [
                'code' => $code['code'],
                'expires_at' => $code['expires_at']?->toIso8601String(),
            ],
            'can_confirm_receipt' => $shipment->status === OrderStatus::Delivered,
            'created_at' => $shipment->created_at?->toIso8601String(),
            'updated_at' => $shipment->updated_at?->toIso8601String(),
        ];
    }
}
