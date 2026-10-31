<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\DeliveryOtp;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeliveryOtpService
{
    public function send(Rider $rider, SellerOrder $parcel): void
    {
        DB::transaction(function () use ($rider, $parcel): void {
            $parcel = SellerOrder::query()->lockForUpdate()->findOrFail($parcel->id);
            app(RiderOperationsService::class)->assertDeliveryAssigned($rider, $parcel);
            ParcelIncidentService::assertOperationsAllowed($parcel);
            if ($parcel->status !== OrderStatus::OutForDelivery) {
                throw ValidationException::withMessages(['delivery_otp' => 'A delivery code can only be sent while the parcel is out for delivery.']);
            }
            $buyer = $parcel->order?->buyer;
            if ($buyer === null || blank($buyer->email) || $buyer->email_verified_at === null) {
                throw ValidationException::withMessages(['delivery_otp' => 'The buyer needs a verified email address to receive the delivery code.']);
            }
            $otp = DeliveryOtp::query()->where('seller_order_id', $parcel->id)->lockForUpdate()->first()
                ?? new DeliveryOtp;
            if ($otp->sent_at?->gt(now()->subSeconds(60))) {
                throw ValidationException::withMessages(['delivery_otp' => 'Please wait 60 seconds before requesting another delivery code.']);
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($otp->exists && Hash::check($code, $otp->code_hash));
            $otp->forceFill([
                'seller_order_id' => $parcel->id,
                'buyer_id' => $buyer->id,
                'courier_id' => $parcel->delivery_courier_id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'sent_at' => now(),
                'used_at' => null,
            ])->save();
            // Synchronous mail: failure rolls back the replacement, preserving the previous code.
            $buyer->notify(new DeliveryOtpCode($parcel, $code));
        });
    }

    /** Validate before the delivery transaction so failed-attempt counters cannot roll back with POD/COD. */
    public function verify(Rider $rider, SellerOrder $parcel, ?string $code): string
    {
        $result = DB::transaction(function () use ($rider, $parcel, $code): array {
            $parcel = SellerOrder::query()->lockForUpdate()->findOrFail($parcel->id);
            app(RiderOperationsService::class)->assertDeliveryAssigned($rider, $parcel);
            $otp = DeliveryOtp::query()->where('seller_order_id', $parcel->id)->lockForUpdate()->first();
            $error = $this->invalidReason($otp, $parcel);
            if ($error !== null) {
                return ['error' => $error];
            }
            if (! is_string($code) || ! preg_match('/\A[0-9]{6}\z/', $code) || ! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'The delivery code is incorrect. Ask the buyer for the latest code.'];
            }

            return ['hash' => $otp->code_hash];
        });
        if (isset($result['error'])) {
            throw ValidationException::withMessages(['delivery_otp' => $result['error']]);
        }

        return $result['hash'];
    }

    /** Called while the parcel is locked inside the existing delivery transaction. */
    public function consume(SellerOrder $parcel, string $verifiedHash): void
    {
        $otp = DeliveryOtp::query()->where('seller_order_id', $parcel->id)->lockForUpdate()->first();
        $error = $this->invalidReason($otp, $parcel);
        if ($error !== null || ! hash_equals($otp->code_hash, $verifiedHash)) {
            throw ValidationException::withMessages(['delivery_otp' => $error ?? 'The delivery code changed. Ask the buyer for the latest code.']);
        }
        $otp->forceFill(['used_at' => now()])->save();
    }

    private function invalidReason(?DeliveryOtp $otp, SellerOrder $parcel): ?string
    {
        if ($otp === null || (int) $otp->courier_id !== (int) $parcel->delivery_courier_id || (int) $otp->buyer_id !== (int) $parcel->order?->buyer_id) {
            return 'Request a delivery code for this parcel and assigned rider first.';
        }
        if ($otp->used_at !== null) {
            return 'This delivery code has already been used.';
        }
        if ($otp->expires_at->lte(now())) {
            return 'The delivery code has expired. Request a new code.';
        }
        if ($otp->attempts >= 5) {
            return 'Too many incorrect delivery codes. Request a new code.';
        }

        return null;
    }
}
