<?php

namespace App\Services\Rider;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Rider\Courier;
use App\Models\Shared\SellerOrder;

/**
 * The digital waybill rule the Rider web page and the Rider mobile API share:
 * a rider sees a parcel's waybill only for their own pickup (READY_FOR_PICKUP /
 * PICKED_UP) or delivery (ASSIGNED_TO_RIDER / OUT_FOR_DELIVERY /
 * DELIVERY_FAILED), with the seller's contact on a pickup and the buyer's on
 * a delivery, never both.
 */
class RiderWaybillService
{
    public const PICKUP_STATUSES = [OrderStatus::ReadyForPickup, OrderStatus::PickedUp];

    public const DELIVERY_STATUSES = [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed];

    /** 'pickup' or 'delivery'; 403 when the waybill is not this courier's to see. */
    public function context(Courier $courier, SellerOrder $sellerOrder): string
    {
        $isPickup = (int) $sellerOrder->courier_id === (int) $courier->id
            && in_array($sellerOrder->status, self::PICKUP_STATUSES, true);
        $isDelivery = (int) $sellerOrder->delivery_courier_id === (int) $courier->id
            && in_array($sellerOrder->status, self::DELIVERY_STATUSES, true);

        abort_unless($isPickup || $isDelivery, 403, 'This waybill is not available for your assignment.');

        return $isPickup ? 'pickup' : 'delivery';
    }

    /**
     * The waybill's fields, as the web page shows them.
     *
     * @return array<string, mixed>
     */
    public function present(SellerOrder $sellerOrder, string $context): array
    {
        $sellerOrder->loadMissing(['order.buyer', 'seller', 'orderItems', 'payment']);
        $addr = $sellerOrder->order?->shipping_address ?? [];
        $payment = $sellerOrder->payment;
        $seller = $sellerOrder->seller;
        $pickup = $context === 'pickup';
        $pickupAddress = collect([
            $seller?->house_number,
            $seller?->street,
            $seller?->barangay_name,
            $seller?->municipality_name,
            $seller?->province_name,
        ])->filter()->implode(', ');
        $isCod = $payment?->method === PaymentMethod::Cod;

        return [
            'context' => $context,
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => $sellerOrder->tracking_number,
            'status' => $sellerOrder->status->value,
            'status_label' => $sellerOrder->status->label(),
            'weight' => $sellerOrder->formattedParcelWeight(),
            'item_count' => (int) $sellerOrder->orderItems->sum('quantity'),
            'seller' => $pickup ? [
                'name' => $seller?->business_name ?? $seller?->fullName() ?? 'Seller',
                'address' => $pickupAddress !== '' ? $pickupAddress : null,
                'phone' => $seller?->contact_no,
            ] : null,
            'buyer' => $pickup ? null : [
                'name' => $addr['name'] ?? $sellerOrder->order?->buyer?->fullName() ?? 'Buyer',
                'address' => $addr['full'] ?? null,
                'phone' => $addr['phone'] ?? $sellerOrder->order?->buyer?->contact_no,
            ],
            // Without the buyer's contact, only the destination area is shown.
            'destination_area' => $addr['municipality'] ?? $sellerOrder->buyerMunicipality(),
            'payment_method' => $payment?->method?->value,
            'payment_label' => $payment?->method?->label(),
            'collect_cod_minor' => $isCod && ($payment->requiresCollection() ?? false) ? (int) $payment->amount_minor : 0,
        ];
    }
}
