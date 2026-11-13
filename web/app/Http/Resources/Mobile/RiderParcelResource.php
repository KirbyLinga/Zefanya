<?php

namespace App\Http\Resources\Mobile;

use App\Models\Shared\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parcel as the Rider mobile app sees it.
 *
 * Read-only projection of a SellerOrder already scoped to the rider by
 * RiderOperationsService. Every amount is server-computed (minor units);
 * private proof-photo paths and recipient evidence are never exposed.
 * Buyer contact details are only included on the delivery leg, where the
 * rider actually needs them; the pickup leg sees the destination area only.
 *
 * @mixin SellerOrder
 */
class RiderParcelResource extends JsonResource
{
    /** "pickup" or "delivery"; set by the controller that listed the parcel. */
    public string $leg = 'delivery';

    public function withLeg(string $leg): static
    {
        $this->leg = $leg;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SellerOrder $parcel */
        $parcel = $this->resource;
        $address = $parcel->order?->shipping_address ?? [];
        $payment = $parcel->payment;
        $collect = $payment !== null && $payment->requiresCollection();
        $isDelivery = $this->leg === 'delivery';

        return [
            'id' => $parcel->id,
            'order_id' => $parcel->order_id,
            'leg' => $this->leg,
            'tracking_number' => $parcel->tracking_number,
            'status' => $parcel->status->value,
            'status_label' => $parcel->status->label(),
            'seller_name' => $parcel->seller?->business_name ?? $parcel->seller?->fullName(),
            'pickup_area' => $parcel->sellerMunicipality(),
            'buyer_name' => $isDelivery ? ($address['name'] ?? null) : null,
            'buyer_phone' => $isDelivery ? ($address['phone'] ?? null) : null,
            'delivery_address' => $isDelivery ? ($address['full'] ?? null) : null,
            'delivery_area' => $address['municipality'] ?? $parcel->buyerMunicipality(),
            'item_count' => (int) $parcel->orderItems->sum('quantity'),
            'weight' => $parcel->formattedParcelWeight(),
            'payment_method' => $payment?->method?->value,
            'payment_status' => $payment?->status?->value,
            'requires_cod_collection' => $collect,
            'cod_amount_minor' => $collect ? (int) $payment->amount_minor : 0,
            'delivery_attempts' => (int) ($parcel->delivery_attempts ?? 0),
            'pickup_session_id' => $parcel->pickup_session_id,
            // Failed pickup attempts already recorded (the server allows two).
            'pickup_attempts' => $this->leg === 'pickup'
                ? (int) ($parcel->pickup_attempts_max_attempt_number ?? $parcel->pickupAttempts()->max('attempt_number') ?? 0)
                : null,
            'updated_at' => $parcel->updated_at?->toIso8601String(),
        ];
    }
}
