<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DeliveryHandoverException;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H1: one fail-closed path for every delivery handover.
 */
class OrderDeliveryService
{
    public const int CODE_TTL_MINUTES = 30;
    public const int MAX_ATTEMPTS = 5;
    public const int LOCK_MINUTES = 15;

    /** @var list<string> */
    private const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** @var list<string> */
    private const array PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/octet-stream'];

    private bool $transactionCommitted = false;

    public function issuePass(SellerOrder $sellerOrder, Buyer $buyer): array
    {
        return DB::transaction(function () use ($sellerOrder, $buyer): array {
            $job = SellerOrder::query()->lockForUpdate()->findOrFail($sellerOrder->id);

            $this->assertBuyerOwns($job, $buyer);
            $this->assertStatus($job);

            $code = (string) random_int(100000, 999999);
            $expires = now()->addMinutes(self::CODE_TTL_MINUTES);

            $job->forceFill([
                'delivery_otp_hash' => $this->hashCode($code),
                'delivery_otp_expires_at' => $expires,
                'delivery_otp_attempts' => 0,
                'delivery_otp_locked_until' => null,
                'delivery_otp_used_at' => null,
                'delivery_proof_path' => null,
            ])->save();

            $token = $this->issueToken($job, $expires);

            $this->audit($job, 'buyer', $buyer->id, 'pass_issued', [
                'expires_at' => $expires->toIso8601String(),
            ]);

            return [
                'code' => $code,
                'token' => $token,
                'expires_at' => $expires->toIso8601String(),
            ];
        });
    }

    public function markDelivered(
        SellerOrder $sellerOrder,
        LogisticsProvider $courier,
        array $data,
        ?UploadedFile $photo,
        ?string $ipAddress = null,
    ): SellerOrder {
        DB::beginTransaction();
        try {
            $job = SellerOrder::query()->with('payment')->lockForUpdate()->findOrFail($sellerOrder->id);

            if ((int) $job->assigned_courier_id !== (int) $courier->id) {
                throw new DeliveryHandoverException('This job is not assigned to your account.', 403);
            }

            $this->assertStatus($job);
            $this->assertNotUsed($job);

            $source = $this->verifyHandover($job, $data);

            $proofPath = $this->storeProof($photo);
            $this->settleCod($job, $data);

            $job->forceFill([
                'status' => OrderStatus::Delivered,
                'delivered_at' => $job->delivered_at ?? now(),
                'delivery_proof_path' => $proofPath,
                'delivery_otp_used_at' => now(),
                'delivery_otp_attempts' => 0,
                'delivery_otp_locked_until' => null,
            ])->save();

            $this->audit($job, 'courier', $courier->id, 'delivered', [
                'source' => $source,
                'proof_path' => $proofPath,
            ], $ipAddress);

            if (! $this->transactionCommitted) {
                DB::commit();
            }
            $this->transactionCommitted = false;

            return $job->fresh();
        } catch (\Throwable $e) {
            if (! $this->transactionCommitted) {
                DB::rollBack();
            }
            $this->transactionCommitted = false;
            throw $e;
        }
    }

    public function adminOverride(
        SellerOrder $sellerOrder,
        Admin $admin,
        string $reason,
        ?UploadedFile $photo,
        ?string $ipAddress = null,
    ): SellerOrder {
        return DB::transaction(function () use ($sellerOrder, $admin, $reason, $photo, $ipAddress): SellerOrder {
            $job = SellerOrder::query()->with('payment')->lockForUpdate()->findOrFail($sellerOrder->id);

            $this->assertStatus($job);
            $this->assertNotUsed($job);

            if (trim($reason) === '') {
                throw new DeliveryHandoverException('An override reason is required.', 422);
            }

            $proofPath = $this->storeProof($photo);
            $this->settleCod($job, []);

            $job->forceFill([
                'status' => OrderStatus::Delivered,
                'delivered_at' => $job->delivered_at ?? now(),
                'delivery_proof_path' => $proofPath,
                'delivery_otp_used_at' => now(),
                'delivery_otp_attempts' => 0,
                'delivery_otp_locked_until' => null,
            ])->save();

            $this->audit($job, 'admin', $admin->id, 'admin_override', [
                'reason' => $reason,
                'proof_path' => $proofPath,
            ], $ipAddress);

            return $job->fresh();
        });
    }

    private function verifyHandover(SellerOrder $job, array $data): string
    {
        $hash = $job->delivery_otp_hash;
        if ($hash === null || $hash === '') {
            throw new DeliveryHandoverException('This parcel has no active delivery code.', 422);
        }

        $expires = $job->delivery_otp_expires_at;
        if ($expires === null || now()->greaterThanOrEqualTo($expires)) {
            throw new DeliveryHandoverException('The delivery code has expired. Ask the buyer to refresh it.', 422);
        }

        $lockedUntil = $job->delivery_otp_locked_until;
        if ($lockedUntil !== null && now()->lt($lockedUntil)) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($lockedUntil, true) / 60));

            throw new DeliveryHandoverException(
                "Too many wrong delivery codes. Try again in $minutes minutes.",
                423,
            );
        }

        if ($lockedUntil !== null) {
            $job->forceFill([
                'delivery_otp_attempts' => 0,
                'delivery_otp_locked_until' => null,
            ])->save();
        }

        $code = trim((string) ($data['delivery_otp'] ?? ''));
        $token = trim((string) ($data['delivery_token'] ?? ''));

        if ($code !== '') {
            if (preg_match('/^\d{6}$/', $code) !== 1) {
                throw new DeliveryHandoverException('Enter the 6-digit delivery code shown by the buyer.', 422);
            }

            if (hash_equals($hash, $this->hashCode($code))) {
                return 'code';
            }

            $this->recordWrongAttempt($job);
            $this->transactionCommitted = true;
            DB::commit();

            throw new DeliveryHandoverException('That delivery code is not correct.', 422);
        }

        if ($token !== '') {
            if ($this->tokenMatches($job, $token, $hash, $expires)) {
                return 'token';
            }

            $this->recordWrongAttempt($job);
            $this->transactionCommitted = true;
            DB::commit();
            throw new DeliveryHandoverException('That delivery token is not valid for this parcel.', 422);
        }

        throw new DeliveryHandoverException('Enter the 6-digit delivery code or scan the buyer\'s pass.', 422);
    }

    private function recordWrongAttempt(SellerOrder $job): void
    {
        $attempts = (int) ($job->delivery_otp_attempts ?? 0) + 1;
        $job->delivery_otp_attempts = $attempts;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $job->delivery_otp_locked_until = now()->addMinutes(self::LOCK_MINUTES);
        }

        $job->save();
    }


    private function tokenMatches(SellerOrder $job, string $token, string $hash, Carbon $expires): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$payloadB64, $signatureB64] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payloadB64, $this->key(), true));

        if (! hash_equals($expected, $signatureB64)) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (! is_array($payload)) {
            return false;
        }

        return (int) ($payload['order_id'] ?? 0) === (int) $job->id
            && is_string($payload['nonce'] ?? null)
            && $payload['nonce'] !== ''
            && (int) ($payload['expires_at'] ?? 0) === $expires->timestamp
            && (string) ($payload['version'] ?? '') === $this->version($hash);
    }

    private function issueToken(SellerOrder $job, Carbon $expires): string
    {
        $payload = $this->base64UrlEncode((string) json_encode([
            'order_id' => (int) $job->id,
            'nonce' => bin2hex(random_bytes(16)),
            'expires_at' => $expires->timestamp,
            'version' => $this->version((string) $job->delivery_otp_hash),
        ], JSON_UNESCAPED_SLASHES));

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', $payload, $this->key(), true),
        );

        return $payload.'.'.$signature;
    }

    private function storeProof(?UploadedFile $photo): string
    {
        if ($photo === null) {
            throw new DeliveryHandoverException('A proof-of-delivery photo is required.', 422);
        }

        $extension = strtolower($photo->getClientOriginalExtension());
        $mime = (string) $photo->getClientMimeType();

        if (! in_array($extension, self::PHOTO_EXTENSIONS, true)
            || ! in_array($mime, self::PHOTO_MIMES, true)
            || $photo->getSize() > 5 * 1024 * 1024) {
            throw new DeliveryHandoverException('Proof must be a JPG, PNG or WEBP photo under 5 MB.', 422);
        }

        $path = $photo->store('delivery-proofs', 'local');

        if ($path === false) {
            throw new DeliveryHandoverException('The proof photo could not be stored.', 500);
        }

        return $path;
    }

    private function settleCod(SellerOrder $job, array $data): void
    {
        $payment = $job->payment;

        if ($payment === null
            || $payment->method !== PaymentMethod::Cod
            || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        if (! array_key_exists('cod_amount', $data) || $data['cod_amount'] === null) {
            throw new DeliveryHandoverException(
                'Enter the cash collected: '.number_format($payment->amount_minor / 100, 2).'.',
                422,
            );
        }

        $collectedMinor = (int) round(((float) $data['cod_amount']) * 100);
        if ($collectedMinor !== (int) $payment->amount_minor) {
            throw new DeliveryHandoverException(
                'Cash collected must be exactly '.number_format($payment->amount_minor / 100, 2).'.',
                422,
            );
        }

        $job->payment()->update([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }

    private function assertBuyerOwns(SellerOrder $job, Buyer $buyer): void
    {
        $ownerId = $job->order?->buyer_id;

        if ((int) $ownerId !== (int) $buyer->id) {
            throw new DeliveryHandoverException('This order belongs to another customer.', 403);
        }
    }

    private function assertStatus(SellerOrder $job): void
    {
        $status = $job->status instanceof OrderStatus
            ? $job->status->value
            : (string) $job->status;

        if (! in_array($status, ['OUT_FOR_DELIVERY', 'DELIVERY_FAILED'], true)) {
            throw new DeliveryHandoverException('This parcel is not out for delivery.', 422);
        }
    }

    private function assertNotUsed(SellerOrder $job): void
    {
        if ($job->delivery_otp_used_at !== null) {
            throw new DeliveryHandoverException('This delivery code has already been used.', 422);
        }
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->key());
    }

    private function version(string $hash): string
    {
        return substr(hash_hmac('sha256', $hash, $this->key()), 0, 32);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DeliveryHandoverException('The application key is not configured.', 500);
        }

        return $key;
    }

    private function audit(
        SellerOrder $job,
        string $actorType,
        ?int $actorId,
        string $action,
        array $metadata,
        ?string $ipAddress = null,
    ): void {
        DB::table('delivery_handover_audits')->insert([
            'seller_order_id' => $job->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

}
