<?php

namespace Tests\Support;

use App\Models\Logistics\DeliveryOtp;
use App\Models\Shared\SellerOrder;
use Illuminate\Support\Facades\Hash;

trait ProvidesDeliveryOtp
{
    /** Seed a buyer-authorized challenge for tests of downstream POD/COD behavior. */
    protected function deliveryOtp(SellerOrder $parcel): string
    {
        if (! DeliveryOtp::where('seller_order_id', $parcel->id)->exists()) {
            (new DeliveryOtp)->forceFill([
                'seller_order_id' => $parcel->id,
                'buyer_id' => $parcel->order->buyer_id,
                'courier_id' => $parcel->delivery_courier_id,
                'code_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'sent_at' => now(),
            ])->save();
        }

        return '123456';
    }
}
