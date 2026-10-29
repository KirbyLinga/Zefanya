<?php

namespace App\Http\Resources;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery job, in the shape the Flutter app's `DeliveryJob` wants.
 *
 * @mixin SellerOrder
 */
class DeliveryJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shippingAddress = $this->order?->shipping_address ?? [];
        $payment = $this->payment;
        $method = $payment?->method instanceof PaymentMethod
            ? $payment->method->value
            : ($payment?->method ?? PaymentMethod::Cod->value);
        $paymentStatus = $payment?->status instanceof PaymentStatus
            ? $payment->status->value
            : ($payment?->status ?? PaymentStatus::Pending->value);

        // Only cash still owed is a COD amount. A paid order shows ₱0.00 and
        // the app reads that as "prepaid" rather than asking for money again.
        $codAmount = $payment !== null
            && $method === PaymentMethod::Cod->value
            && $paymentStatus === PaymentStatus::Pending->value
                ? round($payment->amount_minor / 100, 2)
                : 0.0;

        return [
            'id' => (string) $this->id,
            'order_id' => (string) ($this->order?->id ?? ''),
            'shop_name' => $this->seller?->business_name ?? '',
            'pickup_address' => $this->pickupAddress(),
            'buyer_name' => $shippingAddress['name'] ?? '',
            'delivery_area' => $this->delivery_area ?? '',
            'package_size' => $this->packageSize(),
            'fee' => $this->delivery_fee_minor !== null
                ? round($this->delivery_fee_minor / 100, 2)
                : 0.0,
            'tracking_number' => $this->tracking_number,
            'payment_method' => $method,
            'payment_status' => $paymentStatus,
            'cod_amount' => $codAmount,
            'delivery_otp_required' => $this->delivery_otp_hash !== null,
            'delivery_attempts' => $this->delivery_attempts ?? 0,
            'failure_reason' => $this->failure_reason,
        ];
    }

    private function pickupAddress(): string
    {
        $seller = $this->seller;
        if ($seller === null) {
            return '';
        }

        $parts = array_filter([
            $seller->house_number,
            $seller->street,
            $seller->barangay_name,
            $seller->municipality_name,
            $seller->province_name,
        ]);

        return implode(', ', $parts) ?: ($seller->business_name ?? '');
    }

    private function packageSize(): string
    {
        $count = $this->orderItems?->sum('quantity') ?? 0;
        $label = $count === 1 ? '1 item' : "$count items";
        $amount = $this->subtotal_minor !== null
            ? ' · ₱'.number_format($this->subtotal_minor / 100, 2)
            : '';

        return $label.$amount;
    }
}
