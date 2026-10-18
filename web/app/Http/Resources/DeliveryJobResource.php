<?php

namespace App\Http\Resources;

use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery job, in the shape the Flutter app's `DeliveryJob` wants.
 *
 * ## Why a seller_order is a job
 *
 * A seller_order is the unit of physical fulfilment: one parcel, one seller,
 * one buyer address. Adding `assigned_courier_id` turns it into an assigned
 * job without duplicating any data. This resource translates between the
 * server's relational model and the app's flat `DeliveryJob` value object.
 *
 * ## Field mapping
 *
 * | App field       | Source                                          |
 * |-----------------|------------------------------------------------|
 * | id              | seller_orders.id (string)                       |
 * | orderId         | orders.id (string) — the checkout reference     |
 * | shopName        | sellers.business_name                           |
 * | pickupAddress   | sellers address fields, joined                  |
 * | buyerName       | orders.shipping_address['name']                 |
 * | deliveryArea    | seller_orders.delivery_area (snapshotted)       |
 * | packageSize     | derived from item count and subtotal            |
 * | fee             | seller_orders.delivery_fee_minor / 100          |
 *
 * @mixin SellerOrder
 */
class DeliveryJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shippingAddress = $this->order?->shipping_address ?? [];

        return [
            // String id — the app keys its job list and milestone map on this.
            'id' => (string) $this->id,
            // The parent order's id — lets the app correlate a job with
            // the buyer's order history (`CourierState.jobForOrder`).
            'order_id' => (string) ($this->order?->id ?? ''),
            'shop_name' => $this->seller?->business_name ?? '',
            'pickup_address' => $this->pickupAddress(),
            'buyer_name' => $shippingAddress['name'] ?? '',
            'delivery_area' => $this->delivery_area ?? '',
            // A human-readable description of the parcel's contents and size.
            'package_size' => $this->packageSize(),
            // Pesos, not centavos: the app's DeliveryJob.fee is a double in
            // pesos (it comes from mock data as a plain number like 85.00).
            // Unlike order money, this field is not named _minor because the
            // app reads it directly as a peso amount for display.
            'fee' => $this->delivery_fee_minor !== null
                ? round($this->delivery_fee_minor / 100, 2)
                : 0.0,
        ];
    }

    /**
     * The seller's own address, joined into one readable string.
     *
     * This is a best-effort construction: a seller who entered their address
     * using the manual mode has `street`, `barangay_name` etc.; the display
     * is the same shape the buyer's address snapshot uses.
     */
    private function pickupAddress(): string
    {
        $seller = $this->seller;
        if ($seller === null) return '';

        $parts = array_filter([
            $seller->house_number,
            $seller->street,
            $seller->barangay_name,
            $seller->municipality_name,
            $seller->province_name,
        ]);

        return implode(', ', $parts) ?: ($seller->business_name ?? '');
    }

    /**
     * A short description of the parcel: item count and formatted subtotal.
     */
    private function packageSize(): string
    {
        $count = $this->orderItems?->sum('quantity') ?? 0;
        $label = $count === 1 ? '1 item' : "$count items";
        $amount = $this->subtotal_minor !== null
            ? ' · ₱' . number_format($this->subtotal_minor / 100, 2)
            : '';

        return $label . $amount;
    }
}
