<?php

namespace App\Services;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Notifications\Logistics\RiderAssignmentsNeedReassignment;
use App\Notifications\Rider\AccountStatusChanged;
use App\Notifications\Rider\RiderRegistrationOtp;
use App\Notifications\Rider\StationChanged;
use App\Support\EmailRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class RiderRegistrationService
{
    use VerifiesEmailOtp;

    public function __construct(
        private readonly RiderNotifier $notifier = new RiderNotifier,
    ) {}

    public function register(array $data): Rider
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($data, &$paths): Rider {
                $provider = LogisticsProvider::whereKey($data['logistics_provider_id'])->lockForUpdate()->first();
                if (! $provider || $provider->status !== LogisticsProviderStatus::Approved || $provider->archived_at !== null) {
                    throw ValidationException::withMessages(['logistics_provider_id' => 'Select an approved Logistics provider.']);
                }
                if (EmailRegistry::rolesFor($data['email']) !== []) {
                    throw ValidationException::withMessages(['email' => 'This email is already in use.']);
                }
                $rider = new Rider(Arr::only($data, [
                    'first_name', 'last_name', 'birthday', 'email', 'contact_no',
                    'license_no', 'license_expiry', 'vehicle_type', 'plate_number',
                ]));
                foreach (['drivers_license' => 'rider-licenses', 'or_cr' => 'rider-or-crs'] as $field => $directory) {
                    $path = $data[$field]->store($directory, 'local');
                    if ($path === false) {
                        throw new HttpException(503, 'We could not store your documents. Please try again.');
                    }
                    $paths[] = $path;
                    $rider->{$field.'_path'} = $path;
                }
                $rider->forceFill([
                    'logistics_provider_id' => $provider->id,
                    'password' => $data['password'],
                    'status' => Rider::STATUS_UNVERIFIED,
                    'address_mode' => $data['address_mode'] ?? null,
                    'province_code' => $data['province'] ?? null,
                    'province_name' => $data['province_name'] ?? null,
                    'municipality_code' => $data['municipality'] ?? null,
                    'municipality_name' => $data['municipality_name'] ?? null,
                    'barangay_code' => $data['barangay'] ?? null,
                    'barangay_name' => $data['barangay_name'] ?? null,
                    'street' => $data['street'] ?? null,
                    'house_number' => $data['house_number'] ?? null,
                    'address_detail' => $data['address_detail'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                ])->save();
                $otp = $rider->issueOtp();
                $this->sendOtp($rider, $otp);

                return $rider;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            if ($exception instanceof UniqueConstraintViolationException) {
                $field = 'email';
                foreach (['contact_no', 'license_no', 'plate_number'] as $candidate) {
                    if (str_contains($exception->getMessage(), $candidate)) {
                        $field = $candidate;
                        break;
                    }
                }
                throw ValidationException::withMessages([$field => 'This value is already registered.']);
            }
            throw $exception;
        }
    }

    public function resume(string $email, string $password): Rider
    {
        $rider = Rider::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        // Check a dummy hash as well, avoiding an inexpensive missing-account path.
        $hash = $rider?->password ?? Hash::make(bin2hex(random_bytes(32)));
        if (! Hash::check($password, $hash) || ! $rider || $rider->status !== Rider::STATUS_UNVERIFIED) {
            throw ValidationException::withMessages(['email' => 'Unable to resume this registration with those credentials.']);
        }

        return $rider;
    }

    public function verify(Rider $rider, string $otp): void
    {
        // Return failed attempts from the transaction before rendering errors.
        // Throwing inside it would roll back a database-backed rate limiter too.
        $failure = DB::transaction(function () use ($rider, $otp): ?array {
            $row = Rider::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $this->requireUnverified($row);
            if ($this->otpIsLocked('rider', $row->email)) {
                return ['message' => $this->otpLockedMessage('rider', $row->email), 'retry_after' => $this->otpLockSecondsRemaining('rider', $row->email)];
            }
            if (! $row->otpMatches($otp)) {
                $row->increment('otp_attempts');
                $this->registerOtpFailure('rider', $row->email);
                if ($this->otpIsLocked('rider', $row->email)) {
                    return ['message' => $this->otpLockedMessage('rider', $row->email), 'retry_after' => $this->otpLockSecondsRemaining('rider', $row->email)];
                }

                return ['message' => 'That code does not match. Please try again.'];
            }
            if ($row->email_verification_expires_at === null || $row->isOtpExpired()) {
                return ['message' => 'This code has expired. Please request a new one.'];
            }
            $row->forceFill([
                'status' => Rider::STATUS_PENDING, 'email_verified_at' => now(),
                'email_verification_code' => null, 'email_verification_expires_at' => null,
                'otp_attempts' => 0,
            ])->save();
            $this->clearOtpFailures('rider', $row->email);

            return null;
        });
        if ($failure !== null) {
            if (isset($failure['retry_after'])) {
                $this->limited($failure['message'], $failure['retry_after']);
            }
            throw ValidationException::withMessages(['otp' => $failure['message']]);
        }
    }

    public function resend(Rider $rider): void
    {
        DB::transaction(function () use ($rider): void {
            $row = Rider::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $this->requireUnverified($row);
            if ($this->otpResendIsLimited('rider', $row->email)) {
                $this->limited('Too many verification codes requested. Please try again later.', $this->otpResendSecondsRemaining('rider', $row->email));
            }
            $otp = $row->generateOtp();
            $this->sendOtp($row, $otp);
            $row->commitOtp($otp);
            $this->registerOtpResend('rider', $row->email);
        });
    }

    /**
     * Approve or reject an application. Approval is atomic and can never
     * succeed without an approved, active Station Hub owned by the reviewing
     * Logistics company. On approval the rider is linked to exactly one
     * Courier fleet record used by the existing assignment pipeline.
     */
    public function decide(Rider $rider, LogisticsProvider $provider, string $status, ?string $reason, ?int $stationHubId = null): Rider
    {
        abort_unless(in_array($status, [Rider::STATUS_APPROVED, Rider::STATUS_REJECTED], true), 422);

        return DB::transaction(function () use ($rider, $provider, $status, $reason, $stationHubId): Rider {
            $provider = LogisticsProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProvider($provider);
            $row = Rider::whereKey($rider->id)->where('logistics_provider_id', $provider->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== Rider::STATUS_PENDING || $row->email_verified_at === null) {
                throw ValidationException::withMessages(['status' => 'Only email-verified pending applications can be reviewed.']);
            }
            if ($status === Rider::STATUS_REJECTED) {
                if (blank($reason)) {
                    throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
                }
                $row->forceFill([
                    'status' => Rider::STATUS_REJECTED,
                    'decided_by' => $provider->id,
                    'decided_at' => now(),
                    'rejection_reason' => $reason,
                    'status_reason' => $reason,
                    'status_changed_at' => now(),
                ])->save();

                return $row;
            }

            if ($stationHubId === null) {
                throw ValidationException::withMessages(['station_hub_id' => 'Select the Station Hub this rider will serve.']);
            }

            $station = $this->operationalStation($provider, $stationHubId);
            $row->forceFill([
                'status' => Rider::STATUS_APPROVED,
                'station_hub_id' => $station->id,
                'decided_by' => $provider->id,
                'decided_at' => now(),
                'rejection_reason' => null,
                'status_reason' => null,
                'status_changed_at' => now(),
            ])->save();

            $this->linkCourier($row->fresh());

            return $row;
        });
    }

    /**
     * Activate a legacy Logistics-created rider. The station was chosen when
     * the rider was created; activation fails if it is no longer usable.
     */
    public function activateLegacy(Rider $rider, string $password): Rider
    {
        return DB::transaction(function () use ($rider, $password): Rider {
            $row = Rider::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== Rider::STATUS_PENDING_ACTIVATION) {
                throw ValidationException::withMessages(['status' => 'This registration is no longer awaiting activation.']);
            }
            $this->requireOperationalStation($row);

            $row->forceFill([
                'password' => $password,
                'status' => Rider::STATUS_ACTIVE,
                'status_changed_at' => now(),
            ])->save();
            $this->linkCourier($row);

            return $row;
        });
    }

    public function suspend(Rider $rider, LogisticsProvider $provider, ?string $reason = null): Rider
    {
        return $this->changeStatus($rider, $provider, Rider::STATUS_SUSPENDED, $reason);
    }

    public function deactivate(Rider $rider, LogisticsProvider $provider, ?string $reason = null): Rider
    {
        return $this->changeStatus($rider, $provider, Rider::STATUS_DEACTIVATED, $reason);
    }

    public function reactivate(Rider $rider, LogisticsProvider $provider): Rider
    {
        return $this->changeStatus($rider, $provider, Rider::STATUS_ACTIVE, null);
    }

    /**
     * Move a rider to another Station Hub. Only the owning Logistics company
     * may do this, and only to its own approved, active station.
     */
    public function changeStation(Rider $rider, LogisticsProvider $provider, int $stationHubId): Rider
    {
        $station = null;
        $previousStationName = null;
        $changed = false;

        $row = DB::transaction(function () use ($rider, $provider, $stationHubId, &$station, &$previousStationName, &$changed): Rider {
            $provider = LogisticsProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProvider($provider);
            $row = Rider::whereKey($rider->id)->where('logistics_provider_id', $provider->id)->lockForUpdate()->firstOrFail();
            $station = $this->operationalStation($provider, $stationHubId);

            // Re-selecting the current Station Hub is a no-op, not an event.
            if ((int) $row->station_hub_id === (int) $station->id) {
                return $row;
            }

            $previousStationName = $row->station?->name;

            $row->forceFill(['station_hub_id' => $station->id])->save();

            // A coverage link from the old station is no longer valid.
            $courier = $row->courier;
            if ($courier !== null && $courier->areas() instanceof BelongsToMany) {
                $invalidAreas = $courier->areas()->where(function ($query) use ($station, $courier): void {
                    $query->where('station_hub_id', '<>', $station->id)->orWhereNull('station_hub_id')
                        ->orWhere('areas.logistics_provider_id', '<>', $courier->logistics_provider_id);
                })->pluck('areas.id');
                $courier->areas()->detach($invalidAreas);
            }
            if ($courier !== null && $courier->area_id !== null) {
                $area = Area::query()->find($courier->area_id);
                if ($area === null || (int) $area->station_hub_id !== (int) $station->id) {
                    $courier->forceFill(['area_id' => null])->save();
                }
            }

            $changed = true;

            return $row;
        });

        if ($changed && $station !== null) {
            $this->notifier->send($row, new StationChanged($row, $station, $previousStationName));
        }

        return $row;
    }

    /**
     * Create/refresh the single Courier fleet record for a rider. Called inside
     * an existing transaction during approval or legacy activation.
     */
    public function linkCourier(Rider $rider): Courier
    {
        $station = $rider->station()->first();
        $weight = match (strtolower((string) $rider->vehicle_type)) {
            'van' => 30000,
            'car' => 20000,
            default => 30000,
        };

        $courier = Courier::query()->firstOrNew(['rider_id' => $rider->id]);
        $courier->fill([
            'logistics_provider_id' => $rider->logistics_provider_id,
            'name' => $rider->fullName(),
            'phone' => $rider->contact_no,
            'service_area' => $station?->areas()->pluck('municipality')->filter()->values()->all() ?? [],
            'vehicle_type' => strtolower((string) $rider->vehicle_type),
            'max_weight_grams' => $weight,
        ]);
        if (! $courier->exists) {
            $courier->is_available = true;
        }
        $courier->save();

        return $courier;
    }

    /**
     * Confirm the rider has a usable station before legacy activation.
     */
    public function requireOperationalStation(Rider $rider): LogisticsHub
    {
        $station = $rider->station()->first();
        if ($station === null
            || (int) $station->logistics_provider_id !== (int) $rider->logistics_provider_id
            || ! $station->isOperational()) {
            abort(403, 'This rider is not assigned to an approved, active Station Hub.');
        }

        return $station;
    }

    public function authorizeProvider(LogisticsProvider $provider): void
    {
        abort_unless($provider->status === LogisticsProviderStatus::Approved && $provider->archived_at === null, 403, 'Only approved Logistics providers may manage riders.');
    }

    private function changeStatus(Rider $rider, LogisticsProvider $provider, string $target, ?string $reason): Rider
    {
        $flagged = 0;
        $row = DB::transaction(function () use ($rider, $provider, $target, $reason, &$flagged): Rider {
            $provider = LogisticsProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProvider($provider);
            $row = Rider::whereKey($rider->id)->where('logistics_provider_id', $provider->id)->lockForUpdate()->firstOrFail();

            $allowed = match ($target) {
                Rider::STATUS_SUSPENDED => [Rider::STATUS_APPROVED, Rider::STATUS_ACTIVE],
                Rider::STATUS_DEACTIVATED => [Rider::STATUS_APPROVED, Rider::STATUS_ACTIVE, Rider::STATUS_SUSPENDED],
                Rider::STATUS_ACTIVE => [Rider::STATUS_SUSPENDED, Rider::STATUS_DEACTIVATED],
                default => [],
            };

            if (! in_array($row->status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$row->status} rider cannot be changed to {$target}.",
                ]);
            }

            if ($target === Rider::STATUS_ACTIVE) {
                $this->requireOperationalStation($row);
            }

            $row->forceFill([
                'status' => $target,
                'status_reason' => $target === Rider::STATUS_ACTIVE ? null : $reason,
                'status_changed_at' => now(),
            ])->save();

            $courier = $row->courier;
            if ($courier === null) {
                return $row;
            }

            if ($target === Rider::STATUS_ACTIVE) {
                if (! $this->hasFlaggedAssignments($courier)) {
                    $courier->forceFill(['is_available' => true])->save();
                }

                return $row;
            }

            $courier->forceFill(['is_available' => false])->save();
            $flagged = $this->flagAssignments($row, $courier, $target, $reason);

            return $row;
        });

        // Store the rider-facing row first: it is the authoritative in-app
        // record and cannot fail on a mail transport. The provider e-mail is
        // best-effort by comparison.
        $this->notifier->send($row, new AccountStatusChanged($row, $target, $reason));

        if ($flagged > 0) {
            $provider->notify(new RiderAssignmentsNeedReassignment($row, $flagged));
        }

        return $row;
    }

    private function operationalStation(LogisticsProvider $provider, int $stationHubId): LogisticsHub
    {
        $station = LogisticsHub::query()
            ->whereKey($stationHubId)
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->lockForUpdate()
            ->first();

        if ($station === null) {
            throw ValidationException::withMessages(['station_hub_id' => 'Select a Station Hub owned by your company.']);
        }

        if (! $station->isOperational()) {
            throw ValidationException::withMessages(['station_hub_id' => 'The selected Station Hub must be approved and active.']);
        }

        return $station;
    }

    /**
     * Flag (never rewrite) every non-final parcel currently assigned to the
     * rider's Courier so Logistics can reassign it.
     */
    private function flagAssignments(Rider $rider, Courier $courier, string $target, ?string $reason): int
    {
        $message = "Rider {$rider->fullName()} was {$target}".($reason ? ": {$reason}" : '.');

        return SellerOrder::query()
            ->where(function ($query) use ($courier): void {
                $query->where(function ($pickup) use ($courier): void {
                    $pickup->where('courier_id', $courier->id)->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]);
                })->orWhere(function ($delivery) use ($courier): void {
                    $delivery->where('delivery_courier_id', $courier->id)->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed]);
                });
            })
            ->update([
                'needs_reassignment' => true,
                'reassignment_reason' => $message,
                'reassignment_flagged_at' => now(),
            ]);
    }

    private function hasFlaggedAssignments(Courier $courier): bool
    {
        return SellerOrder::query()
            ->where('needs_reassignment', true)
            ->where(function ($query) use ($courier): void {
                $query->where(function ($pickup) use ($courier): void {
                    $pickup->where('courier_id', $courier->id)->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]);
                })->orWhere(function ($delivery) use ($courier): void {
                    $delivery->where('delivery_courier_id', $courier->id)->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed]);
                });
            })
            ->exists();
    }

    private function requireUnverified(Rider $rider): void
    {
        if ($rider->status !== Rider::STATUS_UNVERIFIED) {
            throw ValidationException::withMessages(['status' => 'This registration is no longer awaiting email verification.']);
        }
    }

    private function sendOtp(Rider $rider, string $otp): void
    {
        try {
            $rider->notify(new RiderRegistrationOtp($otp));
        } catch (Throwable) {
            throw new HttpException(503, 'We could not send your verification email. Please try again.');
        }
    }

    private function limited(string $message, int $seconds): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message, 'errors' => ['otp' => [$message]], 'retry_after' => $seconds,
        ], 429, ['Retry-After' => $seconds]));
    }
}
