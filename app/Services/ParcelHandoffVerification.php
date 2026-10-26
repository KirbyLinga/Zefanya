<?php

namespace App\Services;

use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Short-lived, single-use server-side proof that a Rider or Logistics user
 * verified a parcel (camera QR or manual tracking) before a handoff.
 *
 * Prefer assertValid → business work → consume so failed form validation
 * (e.g. invalid COD) does not burn the token.
 */
class ParcelHandoffVerification
{
    public const ACTOR_RIDER = 'rider';

    public const ACTOR_LOGISTICS = 'logistics';

    public const METHOD_CAMERA = 'camera';

    public const METHOD_MANUAL = 'manual';

    public const ACTION_CONFIRM_PICKUP = 'confirm_pickup';

    public const ACTION_START_DELIVERY = 'start_delivery';

    public const ACTION_COMPLETE_DELIVERY = 'complete_delivery';

    public const ACTION_FAIL_DELIVERY = 'fail_delivery';

    public const ACTION_STATION_RECEIPT = 'confirm_station_receipt';

    public const ACTION_SORT = 'sort_parcel';

    public const ACTION_MANIFEST_DISPATCH = 'manifest_dispatch';

    public const ACTION_MANIFEST_RECEIVE = 'manifest_receive';

    public const ACTION_HANDOFF_DISPATCH = 'handoff_dispatch';

    public const ACTION_HANDOFF_RECEIVE = 'handoff_receive';

    public const ACTION_RETURN_ORIGIN = 'return_origin_receipt';

    public const ACTION_RETURN_HANDOVER = 'return_seller_handover';

    public function issueForSellerReturn(Seller $seller, ParcelReturn $return, string $condition, string $code): string
    {
        $return = $return->fresh(['sellerOrder']);
        if (! $seller->isApproved() || (int) $return->sellerOrder->seller_id !== (int) $seller->id
            || $return->status !== ParcelReturnStatus::AwaitingSeller
            || ! $return->handover_expires_at?->isFuture()
            || ! in_array($condition, ['resellable', 'damaged', 'pending_review'], true)
            || ! hash_equals((string) $return->handover_code_hash, hash('sha256', $code))) {
            throw ValidationException::withMessages(['handover_code' => 'A valid owning-Seller physical handover is required.']);
        }

        return $this->put($this->sellerReturnExpectation($seller, $return, $condition));
    }

    public function assertForSellerReturn(Seller $seller, ParcelReturn $return, string $condition, ?string $token, bool $consume = false): array
    {
        return $this->matchOrFail($this->sellerReturnExpectation($seller, $return, $condition), $token, $consume);
    }

    private function sellerReturnExpectation(Seller $seller, ParcelReturn $return, string $condition): array
    {
        return ['actor_type' => 'seller', 'actor_id' => (int) $seller->id,
            'seller_order_id' => (int) $return->seller_order_id, 'tracking_number' => (string) $return->sellerOrder->tracking_number,
            'action' => 'seller_return_receipt', 'return_id' => (int) $return->id,
            'return_hub_id' => (int) $return->origin_hub_id, 'return_nonce' => $return->handover_nonce,
            'return_condition' => $condition];
    }

    /**
     * @deprecated Use the constant directly when the TTL matters for UX, e.g.
     * rider proof-of-delivery drafts. Kept here so one change updates both the
     * server cache expiry and the client-side draft TTL.
     */
    public const TTL_MINUTES = 10;

    public function issueForRider(
        Rider $rider,
        SellerOrder $sellerOrder,
        string $action,
        string $method = self::METHOD_MANUAL,
    ): string {
        return $this->put([
            'actor_type' => self::ACTOR_RIDER,
            'actor_id' => (int) $rider->id,
            'provider_id' => null,
            'station_hub_id' => null,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'method' => $this->normalizeMethod($method),
            'tracking_number' => (string) $sellerOrder->tracking_number,
        ]);
    }

    public function issueForLogistics(
        LogisticsProvider $provider,
        SellerOrder $sellerOrder,
        string $action,
        string $method = self::METHOD_MANUAL,
        ?int $stationHubId = null,
    ): string {
        if ($stationHubId === null) {
            throw ValidationException::withMessages([
                'station_hub' => 'No authorized Station Hub covers this parcel origin. Configure validated barangay coverage before confirming receipt or sorting.',
            ]);
        }

        return $this->put([
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'station_hub_id' => $stationHubId,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'method' => $this->normalizeMethod($method),
            'tracking_number' => (string) $sellerOrder->tracking_number,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertValidForRider(
        Rider $rider,
        SellerOrder $sellerOrder,
        string $action,
        ?string $token,
    ): array {
        return $this->matchOrFail([
            'actor_type' => self::ACTOR_RIDER,
            'actor_id' => (int) $rider->id,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'tracking_number' => (string) $sellerOrder->tracking_number,
        ], $token, consume: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeForRider(
        Rider $rider,
        SellerOrder $sellerOrder,
        string $action,
        ?string $token,
    ): array {
        return $this->matchOrFail([
            'actor_type' => self::ACTOR_RIDER,
            'actor_id' => (int) $rider->id,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'tracking_number' => (string) $sellerOrder->tracking_number,
        ], $token, consume: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertValidForLogistics(
        LogisticsProvider $provider,
        SellerOrder $sellerOrder,
        string $action,
        ?string $token,
        ?int $expectedStationHubId = null,
    ): array {
        return $this->matchOrFail([
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'tracking_number' => (string) $sellerOrder->tracking_number,
            'station_hub_id' => $expectedStationHubId,
        ], $token, consume: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeForLogistics(
        LogisticsProvider $provider,
        SellerOrder $sellerOrder,
        string $action,
        ?string $token,
        ?int $expectedStationHubId = null,
    ): array {
        return $this->matchOrFail([
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'seller_order_id' => (int) $sellerOrder->id,
            'action' => $action,
            'tracking_number' => (string) $sellerOrder->tracking_number,
            'station_hub_id' => $expectedStationHubId,
        ], $token, consume: true);
    }

    /**
     * Manifest scans are bound to the exact manifest item AND the expected
     * origin/destination hub. This prevents a valid parcel token from being
     * replayed against a different manifest or the wrong side of the transfer.
     */
    public function issueForManifestItem(
        LogisticsProvider $provider,
        ManifestItem $item,
        string $action,
        int $hubId,
        string $method = self::METHOD_MANUAL,
    ): string {
        $order = $item->sellerOrder;

        if ($order === null) {
            throw ValidationException::withMessages([
                'verification_token' => 'The manifest parcel could not be resolved.',
            ]);
        }

        return $this->put([
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'station_hub_id' => $hubId,
            'seller_order_id' => (int) $order->id,
            'manifest_id' => (int) $item->manifest_id,
            'manifest_item_id' => (int) $item->id,
            'action' => $action,
            'method' => $this->normalizeMethod($method),
            'tracking_number' => (string) ($item->tracking_number ?? $order->tracking_number),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertValidForManifestItem(
        LogisticsProvider $provider,
        ManifestItem $item,
        string $action,
        ?string $token,
        int $expectedHubId,
    ): array {
        return $this->matchOrFail(
            $this->manifestItemExpectation($provider, $item, $action, $expectedHubId),
            $token,
            consume: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeForManifestItem(
        LogisticsProvider $provider,
        ManifestItem $item,
        string $action,
        ?string $token,
        int $expectedHubId,
    ): array {
        return $this->matchOrFail(
            $this->manifestItemExpectation($provider, $item, $action, $expectedHubId),
            $token,
            consume: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestItemExpectation(
        LogisticsProvider $provider,
        ManifestItem $item,
        string $action,
        int $expectedHubId,
    ): array {
        $order = $item->sellerOrder;

        return [
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'seller_order_id' => (int) ($order?->id ?? 0),
            'manifest_id' => (int) $item->manifest_id,
            'manifest_item_id' => (int) $item->id,
            'action' => $action,
            'tracking_number' => (string) ($item->tracking_number ?? $order?->tracking_number ?? ''),
            'station_hub_id' => $expectedHubId,
        ];
    }

    /**
     * Handoff scans are bound to the exact handoff row, the acting provider,
     * the parcel, and the expected hub. A dispatch token can never be replayed
     * on the receiving side (or another handoff).
     */
    public function issueForHandoff(
        LogisticsProvider $provider,
        ParcelHandoff $handoff,
        string $action,
        int $hubId,
        string $method = self::METHOD_MANUAL,
    ): string {
        $order = $handoff->sellerOrder;

        if ($order === null) {
            throw ValidationException::withMessages([
                'verification_token' => 'The handoff parcel could not be resolved.',
            ]);
        }

        return $this->put([
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'station_hub_id' => $hubId,
            'seller_order_id' => (int) $order->id,
            'handoff_id' => (int) $handoff->id,
            'origin_provider_id' => (int) $handoff->origin_provider_id,
            'destination_provider_id' => (int) $handoff->destination_provider_id,
            'action' => $action,
            'method' => $this->normalizeMethod($method),
            'tracking_number' => (string) $order->tracking_number,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertValidForHandoff(
        LogisticsProvider $provider,
        ParcelHandoff $handoff,
        string $action,
        ?string $token,
        int $expectedHubId,
    ): array {
        return $this->matchOrFail(
            $this->handoffExpectation($provider, $handoff, $action, $expectedHubId),
            $token,
            consume: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeForHandoff(
        LogisticsProvider $provider,
        ParcelHandoff $handoff,
        string $action,
        ?string $token,
        int $expectedHubId,
    ): array {
        return $this->matchOrFail(
            $this->handoffExpectation($provider, $handoff, $action, $expectedHubId),
            $token,
            consume: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function handoffExpectation(
        LogisticsProvider $provider,
        ParcelHandoff $handoff,
        string $action,
        int $expectedHubId,
    ): array {
        return [
            'actor_type' => self::ACTOR_LOGISTICS,
            'actor_id' => (int) $provider->id,
            'provider_id' => (int) $provider->id,
            'seller_order_id' => (int) $handoff->seller_order_id,
            'handoff_id' => (int) $handoff->id,
            'origin_provider_id' => (int) $handoff->origin_provider_id,
            'destination_provider_id' => (int) $handoff->destination_provider_id,
            'action' => $action,
            'tracking_number' => (string) ($handoff->sellerOrder?->tracking_number ?? ''),
            'station_hub_id' => $expectedHubId,
        ];
    }

    public static function noteSuffix(?string $method): string
    {
        return match ($method) {
            self::METHOD_CAMERA => ' Verified via camera QR.',
            self::METHOD_MANUAL => ' Verified via manual tracking entry.',
            default => '',
        };
    }

    /**
     * Consume after a successful handoff. Ignores a missing token when a
     * concurrent request already consumed it (status change already applied).
     */
    public function consumeAfterSuccess(callable $consume): void
    {
        try {
            $consume();
        } catch (ValidationException $e) {
            $messages = $e->errors()['verification_token'] ?? [];
            $text = implode(' ', $messages);
            if (! str_contains($text, 'expired or already used')) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(array $payload): string
    {
        $token = Str::random(48);
        Cache::put($this->cacheKey($token), $payload, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function matchOrFail(array $expected, ?string $token, bool $consume): array
    {
        if ($token === null || trim($token) === '') {
            throw ValidationException::withMessages([
                'verification_token' => 'Scan or enter the tracking number before confirming this handoff.',
            ]);
        }

        $key = $this->cacheKey($token);
        $payload = $consume ? Cache::pull($key) : Cache::get($key);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification expired or already used. Scan again, then confirm.',
            ]);
        }

        foreach (['return_id', 'return_hub_id', 'return_nonce', 'return_condition'] as $field) {
            if (array_key_exists($field, $expected) && ($payload[$field] ?? null) !== $expected[$field]) {
                throw ValidationException::withMessages(['verification_token' => 'Return verification does not match this handover.']);
            }
        }

        if (($payload['actor_type'] ?? null) !== ($expected['actor_type'] ?? null)
            || (int) ($payload['actor_id'] ?? 0) !== (int) ($expected['actor_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification belongs to another account.',
            ]);
        }

        if ((int) ($payload['seller_order_id'] ?? 0) !== (int) ($expected['seller_order_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this shipment.',
            ]);
        }

        if (array_key_exists('manifest_id', $expected)
            && (int) ($payload['manifest_id'] ?? 0) !== (int) $expected['manifest_id']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this manifest.',
            ]);
        }

        if (array_key_exists('manifest_item_id', $expected)
            && (int) ($payload['manifest_item_id'] ?? 0) !== (int) $expected['manifest_item_id']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this manifest parcel.',
            ]);
        }

        if (array_key_exists('handoff_id', $expected)
            && (int) ($payload['handoff_id'] ?? 0) !== (int) $expected['handoff_id']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this handoff.',
            ]);
        }

        if (array_key_exists('origin_provider_id', $expected)
            && (int) ($payload['origin_provider_id'] ?? 0) !== (int) $expected['origin_provider_id']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this handoff origin.',
            ]);
        }

        if (array_key_exists('destination_provider_id', $expected)
            && (int) ($payload['destination_provider_id'] ?? 0) !== (int) $expected['destination_provider_id']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this handoff destination.',
            ]);
        }

        if (($payload['action'] ?? null) !== ($expected['action'] ?? null)) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification is not valid for this action.',
            ]);
        }

        if (filled($expected['tracking_number'] ?? null)
            && filled($payload['tracking_number'] ?? null)
            && (string) $payload['tracking_number'] !== (string) $expected['tracking_number']) {
            throw ValidationException::withMessages([
                'verification_token' => 'Parcel verification does not match this tracking number.',
            ]);
        }

        if (($expected['actor_type'] ?? null) === self::ACTOR_LOGISTICS) {
            if ((int) ($payload['provider_id'] ?? 0) !== (int) ($expected['provider_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    'verification_token' => 'Parcel verification belongs to another logistics provider.',
                ]);
            }

            $tokenStation = $payload['station_hub_id'] ?? null;
            $expectedStation = $expected['station_hub_id'] ?? null;

            // Never accept a null/guessed station — both sides must be bound and match.
            if ($expectedStation === null) {
                throw ValidationException::withMessages([
                    'verification_token' => 'No authorized Station Hub can be determined for this parcel.',
                ]);
            }

            if ($tokenStation === null || (int) $tokenStation !== (int) $expectedStation) {
                throw ValidationException::withMessages([
                    'verification_token' => 'Parcel verification is not valid for this Station Hub.',
                ]);
            }
        }

        return $payload;
    }

    private function normalizeMethod(string $method): string
    {
        return $method === self::METHOD_CAMERA ? self::METHOD_CAMERA : self::METHOD_MANUAL;
    }

    private function cacheKey(string $token): string
    {
        return 'parcel_handoff_verify:'.$token;
    }
}
