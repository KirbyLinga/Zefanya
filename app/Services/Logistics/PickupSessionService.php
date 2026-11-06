<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\PickupSession;
use App\Models\Rider\Courier;
use App\Models\Shared\SellerOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Groups the parcels one rider collects from one seller, at one address, in one
 * pickup window, into a pickup session. The session decides pickup pay (first
 * parcel, then additional parcels); it never changes a parcel's status. Every
 * parcel is still collected with its own verified scan.
 *
 * Lock order everywhere: seller_orders row(s) first, then the pickup_sessions row.
 * Every method that takes a parcel expects the caller to hold that parcel's lock.
 */
class PickupSessionService
{
    public function __construct(
        private readonly PickupPolicy $policy = new PickupPolicy,
        private readonly PickupWindowService $windows = new PickupWindowService,
    ) {}

    public static function addressHash(SellerOrder $parcel): string
    {
        $snapshot = is_array($parcel->pickup_address_snapshot) ? $parcel->pickup_address_snapshot : [];
        $address = (string) ($snapshot['full'] ?? $parcel->pickupAddress() ?? '');
        $barangay = (string) ($snapshot['barangay_code'] ?? $parcel->sellerBarangayCode() ?? '');

        return sha1(mb_strtolower(trim($address)).'|'.trim($barangay));
    }

    /**
     * The rider who already has an open session at this seller and window, so a new
     * parcel can join the same visit. The caller still checks that the rider can take it.
     *
     * @param  list<int>  $providerIds
     */
    public function reusableCourierId(SellerOrder $parcel, array $providerIds): ?int
    {
        if (! PickupPolicy::sessionsAvailable() || $providerIds === []) {
            return null;
        }
        $this->windows->ensureScheduled($parcel);

        $courierId = PickupSession::query()
            ->where('status', PickupSession::OPEN)
            ->where('seller_id', $parcel->seller_id)
            ->where('pickup_address_hash', self::addressHash($parcel))
            ->where('window_starts_at', $parcel->pickup_window_starts_at)
            ->whereIn('logistics_provider_id', $providerIds)
            ->orderBy('id')->value('courier_id');

        return $courierId === null ? null : (int) $courierId;
    }

    /**
     * Put a ready parcel into its rider's open session, creating it when none exists.
     * `$collecting` is for a parcel that is being picked up with no session yet.
     */
    public function attach(SellerOrder $parcel, Courier $courier, bool $collecting = false): ?PickupSession
    {
        if (! PickupPolicy::sessionsAvailable()) {
            return null;
        }
        if (! $collecting && $parcel->status !== OrderStatus::ReadyForPickup) {
            return null;
        }

        $this->windows->ensureScheduled($parcel);

        if ($parcel->pickup_session_id !== null) {
            $current = PickupSession::query()->whereKey($parcel->pickup_session_id)->lockForUpdate()->first();
            if ($current !== null && $current->isActive() && (int) $current->courier_id === (int) $courier->id) {
                return $current;
            }
            $this->detach($parcel, 'reassigned');
        }

        $key = implode('|', [
            $parcel->seller_id, self::addressHash($parcel), $courier->logistics_provider_id,
            $parcel->pickup_window_starts_at->getTimestamp(), $courier->id,
        ]);
        $session = PickupSession::query()->where('open_key', $key)->lockForUpdate()->first()
            ?? $this->create($parcel, $courier, $key);

        $parcel->forceFill(['pickup_session_id' => $session->id])->save();

        return $session;
    }

    /** Take a parcel out of its session (reassigned, cancelled or its window passed) and close the session if it is now empty. */
    public function detach(SellerOrder $parcel, string $reason): void
    {
        if (! PickupPolicy::sessionsAvailable() || $parcel->pickup_session_id === null) {
            return;
        }
        $session = PickupSession::query()->whereKey($parcel->pickup_session_id)->lockForUpdate()->first();
        $parcel->forceFill(['pickup_session_id' => null])->save();
        if ($session !== null) {
            $this->closeIfFinished($session, $reason);
        }
    }

    /**
     * Called inside the PickedUp transition. Starts the session at the first
     * collected parcel (which freezes who is in it) and says whether this parcel
     * is the one paid the first-parcel rate. The session row stays locked for the
     * rest of the transaction, so two scans can never both be first.
     *
     * @return array{session: PickupSession, first: bool}|null null when sessions are not available yet
     */
    public function beginPickup(SellerOrder $parcel, Courier $courier): ?array
    {
        $session = $this->attach($parcel, $courier, collecting: true);
        if ($session === null) {
            return null;
        }

        if ($session->status === PickupSession::OPEN) {
            // Starting within the revisit gap of this rider's last visit here continues it.
            $previous = PickupSession::query()
                ->where('seller_id', $session->seller_id)->where('courier_id', $session->courier_id)
                ->where('pickup_address_hash', $session->pickup_address_hash)
                ->whereKeyNot($session->id)->whereNotNull('started_at')
                ->whereIn('status', [PickupSession::IN_PROGRESS, PickupSession::COMPLETED])
                ->where(fn ($query) => $query->whereNull('closed_at')
                    ->orWhere('closed_at', '>=', now()->subMinutes((int) config('pickup.revisit_gap_minutes', 30))))
                ->orderByDesc('id')->first();

            $session->forceFill([
                'status' => PickupSession::IN_PROGRESS,
                'started_at' => now(),
                'open_key' => null,
                'first_payable' => $previous === null,
                'continuation_of_id' => $previous?->id,
            ])->save();
        }

        $first = $session->first_payable && ! $session->earnings()->where('session_first', 1)->exists();

        return ['session' => $session, 'first' => $first];
    }

    /** After a parcel is collected, close the session when nothing is left to collect in it. */
    public function afterCollected(PickupSession $session): void
    {
        $this->closeIfFinished($session, 'all_collected');
    }

    /** The rider ends the stop. Parcels not collected move to a new session for the next visit. */
    public function finish(PickupSession $session, Courier $courier): void
    {
        DB::transaction(function () use ($session, $courier): void {
            $parcels = SellerOrder::query()->where('pickup_session_id', $session->id)
                ->orderBy('id')->lockForUpdate()->get();
            $locked = PickupSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->courier_id === (int) $courier->id, 403, 'This stop is not assigned to you.');

            if ($locked->status === PickupSession::COMPLETED) {
                return;
            }
            if ($locked->status !== PickupSession::IN_PROGRESS) {
                throw ValidationException::withMessages(['session' => 'Collect at least one parcel before finishing this stop.']);
            }

            foreach ($parcels->where('status', OrderStatus::ReadyForPickup) as $parcel) {
                $parcel->forceFill(['pickup_session_id' => null])->save();
            }
            $locked->forceFill([
                'status' => PickupSession::COMPLETED, 'closed_at' => now(), 'close_reason' => 'rider_finished', 'open_key' => null,
            ])->save();
            foreach ($parcels->where('status', OrderStatus::ReadyForPickup) as $parcel) {
                $this->attach($parcel, $courier);
            }
        }, 3);
    }

    /**
     * Move every ready parcel whose window has passed to the next window. A missed
     * window is not a failed pickup: no attempt is recorded and the parcel keeps its rider.
     */
    public function rollMissedWindows(?CarbonInterface $now = null): int
    {
        if (! PickupPolicy::sessionsAvailable()) {
            return 0;
        }
        $now ??= now();
        $rolled = 0;

        $ids = SellerOrder::query()->where('status', OrderStatus::ReadyForPickup->value)
            ->whereNotNull('pickup_window_ends_at')->where('pickup_window_ends_at', '<', $now)
            ->orderBy('id')->limit(500)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$rolled): void {
                $parcel = SellerOrder::query()->whereKey($id)->lockForUpdate()->first();
                if ($parcel === null || $parcel->status !== OrderStatus::ReadyForPickup
                    || $parcel->pickup_window_ends_at === null || $parcel->pickup_window_ends_at->greaterThanOrEqualTo($now)) {
                    return;
                }
                $this->detach($parcel, 'window_missed');
                $this->windows->schedule($parcel, $now, missed: true);
                $courier = $parcel->courier_id !== null ? Courier::query()->find($parcel->courier_id) : null;
                if ($courier !== null && $courier->isEligibleForAssignment()) {
                    $this->attach($parcel, $courier);
                }
                $rolled++;
            }, 3);
        }

        return $rolled;
    }

    /**
     * What the stop pays so far and at most.
     *
     * @return array{earnedMinor: int, potentialMinor: int, collected: int, total: int}
     */
    public function summary(PickupSession $session): array
    {
        $parcels = $session->parcels()->get(['id', 'status']);
        $pending = $parcels->where('status', OrderStatus::ReadyForPickup)->count();
        $earned = (int) $session->earnings()->sum('amount_minor');
        $firstPaid = $session->earnings()->where('session_first', 1)->exists();

        $remaining = $pending === 0 ? 0 : ($session->first_payable && ! $firstPaid
            ? $session->first_minor + ($pending - 1) * $session->additional_minor
            : $pending * $session->additional_minor);

        return [
            'earnedMinor' => $earned,
            'potentialMinor' => $earned + $remaining,
            'collected' => $parcels->count() - $pending,
            'total' => $parcels->count(),
        ];
    }

    private function closeIfFinished(PickupSession $session, string $reason): void
    {
        if (! $session->isActive()) {
            return;
        }
        if ($session->parcels()->where('status', OrderStatus::ReadyForPickup->value)->exists()) {
            return;
        }

        $session->forceFill([
            'status' => $session->status === PickupSession::OPEN
                ? ($reason === 'window_missed' ? PickupSession::MISSED : PickupSession::CANCELLED)
                : PickupSession::COMPLETED,
            'closed_at' => now(),
            'close_reason' => $reason,
            'open_key' => null,
        ])->save();
    }

    private function create(SellerOrder $parcel, Courier $courier, string $key): PickupSession
    {
        $rates = $this->policy->rates();
        $snapshot = is_array($parcel->pickup_address_snapshot) ? $parcel->pickup_address_snapshot : [];

        $attributes = [
            'logistics_provider_id' => $courier->logistics_provider_id,
            'seller_id' => $parcel->seller_id,
            'courier_id' => $courier->id,
            'pickup_address_hash' => self::addressHash($parcel),
            'pickup_address' => mb_substr((string) ($snapshot['full'] ?? $parcel->pickupAddress() ?? ''), 0, 500) ?: null,
            'pickup_barangay_code' => $snapshot['barangay_code'] ?? $parcel->sellerBarangayCode(),
            'window_key' => $parcel->pickup_window_key ?? 'morning',
            'window_starts_at' => $parcel->pickup_window_starts_at,
            'window_ends_at' => $parcel->pickup_window_ends_at,
            'status' => PickupSession::OPEN,
            'first_minor' => $rates['first_minor'],
            'additional_minor' => $rates['additional_minor'],
            'open_key' => $key,
        ];

        try {
            $session = new PickupSession;
            $session->forceFill($attributes)->save();

            return $session;
        } catch (UniqueConstraintViolationException) {
            // Another parcel from the same seller opened it a moment ago.
            return PickupSession::query()->where('open_key', $key)->lockForUpdate()->firstOrFail();
        }
    }
}
