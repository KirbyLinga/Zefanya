<?php

namespace App\Services\Logistics;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CourierAccount;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\TransferCourier;
use App\Support\CourierAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Courier Mobile app access: issue, activate, sign in, refresh, sign out and
 * revoke. Never touches transfers, assignments, custody or money.
 *
 * Session contract: an account has at most one valid token, named by
 * courier_accounts.current_token_id. Every token-changing write holds the
 * account row lock and re-checks state after acquiring it.
 *
 * Lock order (all paths, so no cycle): logistics_providers → transfer_couriers
 * → courier_accounts → personal_access_tokens. Password and code hashing are
 * done before any lock is taken.
 */
class CourierAccountService
{
    /** Crockford base32: no I, L, O or U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LOGIN_PREFIX = 'ZC-';

    public static function passwordInputError(#[\SensitiveParameter] string $password): ?string
    {
        if (str_contains($password, "\0")) {
            return 'The password must not contain NUL bytes.';
        }

        if (config('hashing.driver', 'bcrypt') === 'bcrypt') {
            $configuredLimit = (int) config('hashing.bcrypt.limit');
            $maxBytes = $configuredLimit > 0 ? min(72, $configuredLimit) : 72;

            if (strlen($password) > $maxBytes) {
                return "The password must not exceed {$maxBytes} bytes.";
            }
        }

        return null;
    }

    /** Validate encoding and metadata, not cryptographic correctness; never generate a verifier here. */
    private static function dummyHash(): ?string
    {
        $hash = config('courier.dummy_hash');
        $algorithm = match (config('hashing.driver', 'bcrypt')) {
            'bcrypt' => 'bcrypt',
            'argon' => 'argon2i',
            'argon2id' => 'argon2id',
            default => null,
        };
        // PHP-generated bcrypt and Argon verifiers have fixed encoded payload
        // lengths. Metadata inspection alone accepts corrupt payloads.
        $format = match ($algorithm) {
            'bcrypt' => '~\A\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}\z~',
            'argon2i', 'argon2id' => '~\A\$'.$algorithm.'\$v=19\$m=[1-9][0-9]*,t=[1-9][0-9]*,p=[1-9][0-9]*\$[A-Za-z0-9+/]{22}\$[A-Za-z0-9+/]{43}\z~',
            default => null,
        };

        return is_string($hash) && $format !== null && preg_match($format, $hash) === 1
            && (password_get_info($hash)['algoName'] ?? null) === $algorithm
            && ! Hash::needsRehash($hash) ? $hash : null;
    }

    // ── Identifiers ─────────────────────────────────────────────────────────

    public static function normalizeLoginId(mixed $value): string
    {
        $raw = strtoupper(substr(is_string($value) ? $value : '', 0, 40));
        $raw = (string) preg_replace('/[^A-Z0-9]/', '', $raw);
        $body = str_starts_with($raw, 'ZC') ? substr($raw, 2) : $raw;

        return self::LOGIN_PREFIX.strtr($body, 'OIL', '011');
    }

    public static function normalizeCode(mixed $value): string
    {
        $raw = strtoupper(substr(is_string($value) ? $value : '', 0, 40));

        return strtr((string) preg_replace('/[^A-Z0-9]/', '', $raw), 'OIL', '011');
    }

    private static function randomString(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    // ── Logistics: issue and revoke ────────────────────────────────────────

    /**
     * Issue (or reissue) a single-use activation code. Invalidates the old
     * code, the old password and every session. The plain code is returned
     * once and is never stored.
     *
     * @return array{0: CourierAccount, 1: string}
     */
    public function issue(LogisticsProvider $actor, TransferCourier $courier): array
    {
        abort_unless(CourierAccess::enabled(), 404);
        abort_unless((int) $courier->logistics_provider_id === (int) $actor->id, 404);

        $code = self::randomString(10);
        $codeHash = Hash::make($code);

        $account = DB::transaction(function () use ($actor, $courier, $codeHash): CourierAccount {
            $provider = LogisticsProvider::query()->whereKey($actor->id)->sharedLock()->first();
            abort_unless($provider !== null && $provider->isApproved(), 403);

            $lockedCourier = TransferCourier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedCourier->logistics_provider_id === (int) $provider->id, 404);
            if (! $lockedCourier->isActive()) {
                throw ValidationException::withMessages([
                    'courier' => 'Reactivate '.$lockedCourier->name.' before issuing app access.',
                ]);
            }

            $account = CourierAccount::query()->where('transfer_courier_id', $lockedCourier->id)->lockForUpdate()->first();
            $before = $account?->status ?? 'none';
            $account ??= $this->createAccount($lockedCourier);

            $account->forceFill([
                'status' => CourierAccount::PENDING,
                'password' => null,
                'activation_code_hash' => $codeHash,
                'activation_expires_at' => now()->addHours((int) config('courier.activation_ttl_hours', 24)),
                'activation_attempts' => 0,
                'credential_generation' => $account->credential_generation + 1,
                'revoked_at' => null,
                'revoked_reason' => null,
            ]);
            $this->endSessions($account);
            $account->save();

            OperationsAuditLog::record($provider, 'courier_app_access_issued', $lockedCourier, $lockedCourier->name,
                ['app_access' => $before], ['app_access' => CourierAccount::PENDING, 'login_id' => $account->login_id]);

            return $account;
        });

        return [$account, $code];
    }

    private function createAccount(TransferCourier $lockedCourier): CourierAccount
    {
        for ($try = 0; $try < 5; $try++) {
            try {
                return DB::transaction(function () use ($lockedCourier): CourierAccount {
                    $account = (new CourierAccount)->forceFill([
                        'transfer_courier_id' => $lockedCourier->id,
                        'login_id' => self::LOGIN_PREFIX.self::randomString(8),
                        'status' => CourierAccount::PENDING,
                    ]);
                    $account->save();

                    return $account->refresh();
                });
            } catch (UniqueConstraintViolationException $e) {
                // A login ID collision: try a new one. A second account for the
                // same Courier cannot happen while its row is locked.
                if (CourierAccount::query()->where('transfer_courier_id', $lockedCourier->id)->exists()) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique Courier login ID.');
    }

    /**
     * Revoke app access immediately. Allowed while the Courier carries a
     * transfer: no transfer, assignment or custody is touched. Works whenever
     * the schema exists, even with the feature turned off.
     */
    public function revoke(?LogisticsProvider $actor, TransferCourier $courier, string $reason): bool
    {
        if (! CourierAccess::schemaReady()) {
            return false;
        }
        if ($actor !== null) {
            abort_unless((int) $courier->logistics_provider_id === (int) $actor->id, 404);
        }

        return DB::transaction(function () use ($actor, $courier, $reason): bool {
            $lockedCourier = TransferCourier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();

            return $this->revokeLocked($actor, $lockedCourier, $reason);
        });
    }

    /**
     * Called inside an existing transaction that already holds the Courier
     * row lock (TransferCourierService::setActive()).
     */
    public function revokeLocked(?LogisticsProvider $actor, TransferCourier $lockedCourier, string $reason): bool
    {
        if (! CourierAccess::schemaReady()) {
            return false;
        }

        $account = CourierAccount::query()->where('transfer_courier_id', $lockedCourier->id)->lockForUpdate()->first();
        if ($account === null || $account->status === CourierAccount::REVOKED) {
            return false;
        }

        $before = $account->status;
        $account->forceFill([
            'status' => CourierAccount::REVOKED,
            'activation_code_hash' => null,
            'activation_expires_at' => null,
            'activation_attempts' => 0,
            'credential_generation' => $account->credential_generation + 1,
            'revoked_at' => now(),
            'revoked_reason' => mb_substr($reason, 0, 200),
        ]);
        $this->endSessions($account);
        $account->save();

        $auditor = $actor ?? LogisticsProvider::query()->find($lockedCourier->logistics_provider_id);
        if ($auditor !== null) {
            OperationsAuditLog::record($auditor, 'courier_app_access_revoked', $lockedCourier, $lockedCourier->name,
                ['app_access' => $before], ['app_access' => CourierAccount::REVOKED], $reason);
        }

        return true;
    }

    /**
     * Provider suspension: end every Courier session of this company. Called
     * inside AccountStatusService::transition() with the provider row locked.
     * Account status is unchanged, so after reapproval each Courier signs in
     * again, and an individually revoked account stays revoked.
     */
    public function endProviderSessions(LogisticsProvider $lockedProvider): int
    {
        if (! CourierAccess::schemaReady()) {
            return 0;
        }

        $accounts = CourierAccount::query()
            ->whereIn('transfer_courier_id', TransferCourier::query()->select('id')->where('logistics_provider_id', $lockedProvider->id))
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($accounts as $account) {
            $this->endSessions($account);
            $account->save();
        }

        return $accounts->count();
    }

    // ── Courier: activate, login, refresh, logout ──────────────────────────

    /**
     * @return array{token: string, expires_at: ?string, expires_in: ?int, session: int}|string
     *                                                                                          The session payload, or 'invalid' / 'ineligible'.
     */
    public function activate(mixed $loginId, mixed $code, string $password, ?string $deviceName): array|string
    {
        if (self::passwordInputError($password) !== null) {
            return 'invalid';
        }

        // Fail identically for every ID when provisioning is missing or stale.
        $dummyHash = self::dummyHash();
        if ($dummyHash === null) {
            return 'invalid';
        }

        $loginId = self::normalizeLoginId($loginId);
        $code = self::normalizeCode($code);
        $passwordHash = Hash::make($password);

        $account = CourierAccount::query()->where('login_id', $loginId)->first();
        if ($account === null || $account->status !== CourierAccount::PENDING || $account->activation_code_hash === null) {
            Hash::check($code, $dummyHash);

            return 'invalid';
        }

        $seenHash = $account->activation_code_hash;
        $seenGeneration = $account->credential_generation;
        $codeMatches = Hash::check($code, $seenHash);
        $courier = TransferCourier::query()->find($account->transfer_courier_id);

        return DB::transaction(function () use ($account, $courier, $seenHash, $seenGeneration, $codeMatches, $passwordHash, $deviceName): array|string {
            $this->lockEligibilityRows($courier);
            /** @var CourierAccount|null $locked */
            $locked = CourierAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CourierAccount::PENDING
                || $locked->activation_code_hash !== $seenHash
                || $locked->credential_generation !== $seenGeneration
                || $locked->activation_expires_at === null || $locked->activation_expires_at->isPast()
                || $locked->activation_attempts >= (int) config('courier.activation_max_attempts', 5)) {
                return 'invalid';
            }

            if (! $codeMatches) {
                $attempts = $locked->activation_attempts + 1;
                $locked->forceFill(['activation_attempts' => $attempts]);
                if ($attempts >= (int) config('courier.activation_max_attempts', 5)) {
                    // The code is used up; Logistics must issue a new one.
                    $locked->forceFill(['activation_code_hash' => null, 'activation_expires_at' => null]);
                }
                $locked->save();

                return 'invalid';
            }

            if (! $this->operationallyEligible($locked)) {
                return 'ineligible';
            }

            $locked->forceFill([
                'status' => CourierAccount::ACTIVE,
                'password' => $passwordHash,
                'activation_code_hash' => null,
                'activation_expires_at' => null,
                'activation_attempts' => 0,
                'activated_at' => now(),
            ]);

            return $this->startSession($locked, $deviceName);
        });
    }

    /**
     * @return array{token: string, expires_at: ?string, expires_in: ?int, session: int}|string
     *                                                                                          The session payload, or 'invalid' / 'ineligible'.
     */
    public function login(mixed $loginId, string $password, ?string $deviceName): array|string
    {
        if (self::passwordInputError($password) !== null) {
            return 'invalid';
        }

        $dummyHash = self::dummyHash();
        if ($dummyHash === null) {
            return 'invalid';
        }

        $account = CourierAccount::query()->where('login_id', self::normalizeLoginId($loginId))->first();
        if ($account === null || $account->password === null || $account->status !== CourierAccount::ACTIVE) {
            Hash::check($password, $dummyHash);

            return 'invalid';
        }

        $seenPassword = $account->password;
        $seenGeneration = $account->credential_generation;
        if (! Hash::check($password, $seenPassword)) {
            return 'invalid';
        }
        $courier = TransferCourier::query()->find($account->transfer_courier_id);

        return DB::transaction(function () use ($account, $courier, $seenPassword, $seenGeneration, $deviceName): array|string {
            $this->lockEligibilityRows($courier);
            /** @var CourierAccount|null $locked */
            $locked = CourierAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CourierAccount::ACTIVE
                || $locked->password !== $seenPassword || $locked->credential_generation !== $seenGeneration) {
                return 'invalid';
            }
            if (! $this->eligible($locked)) {
                return 'ineligible';
            }

            return $this->startSession($locked, $deviceName);
        });
    }

    /**
     * Replace the still-current token with a new one. Returns null when the
     * presented token is no longer the account's current one.
     *
     * @return array{token: string, expires_at: ?string, expires_in: ?int, session: int}|null
     */
    public function refresh(CourierAccount $account, PersonalAccessToken $presented): ?array
    {
        $courier = TransferCourier::query()->find($account->transfer_courier_id);

        return DB::transaction(function () use ($account, $courier, $presented): ?array {
            $this->lockEligibilityRows($courier);
            /** @var CourierAccount|null $locked */
            $locked = CourierAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            $stillValid = PersonalAccessToken::query()->whereKey($presented->id)->first();

            if ($locked === null || $stillValid === null || $locked->status !== CourierAccount::ACTIVE
                || $locked->current_token_id !== (int) $presented->id
                || ($stillValid->expires_at !== null && $stillValid->expires_at->isPast())
                || ! $this->eligible($locked)) {
                return null;
            }

            return $this->startSession($locked, null, touchLogin: false);
        });
    }

    /** Ends only the presented token; a stale logout never ends a newer session. */
    public function logout(CourierAccount $account, PersonalAccessToken $presented): void
    {
        DB::transaction(function () use ($account, $presented): void {
            /** @var CourierAccount|null $locked */
            $locked = CourierAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            PersonalAccessToken::query()->whereKey($presented->id)
                ->where('tokenable_type', $account->getMorphClass())->where('tokenable_id', $account->id)->delete();

            if ($locked !== null && $locked->current_token_id === (int) $presented->id) {
                $locked->forceFill([
                    'current_token_id' => null,
                    'session_generation' => $locked->session_generation + 1,
                ])->save();
            }
        });
    }

    // ── Eligibility ─────────────────────────────────────────────────────────

    /** Fresh, unlocked check used on every request and inside the locked paths. */
    public function eligible(CourierAccount $account): bool
    {
        return $account->isActive() && $this->operationallyEligible($account);
    }

    /** The Courier record is active and its company approved and not archived. */
    public function operationallyEligible(CourierAccount $account): bool
    {
        $courier = TransferCourier::query()->find($account->transfer_courier_id);
        if ($courier === null || ! $courier->isActive()) {
            return false;
        }
        // The not_archived global scope hides archived companies.
        $provider = LogisticsProvider::query()->find($courier->logistics_provider_id);

        return $provider !== null && $provider->isApproved();
    }

    /** Shared locks in the global order: provider, then Courier. */
    private function lockEligibilityRows(?TransferCourier $courier): void
    {
        if ($courier === null) {
            return;
        }
        LogisticsProvider::query()->withoutGlobalScope('not_archived')->whereKey($courier->logistics_provider_id)->sharedLock()->first();
        TransferCourier::query()->whereKey($courier->id)->sharedLock()->first();
    }

    // ── Sessions ────────────────────────────────────────────────────────────

    /** Delete every token, clear the current one and bump the generation. Caller saves. */
    private function endSessions(CourierAccount $account): void
    {
        $account->tokens()->delete();
        $account->forceFill([
            'current_token_id' => null,
            'session_generation' => $account->session_generation + 1,
        ]);
    }

    /**
     * Replace every token of the locked account with one new token.
     *
     * @return array{token: string, expires_at: ?string, expires_in: ?int, session: int}
     */
    private function startSession(CourierAccount $locked, ?string $deviceName, bool $touchLogin = true): array
    {
        $locked->tokens()->delete();
        $name = 'courier:'.mb_substr(trim((string) ($deviceName ?? '')) ?: 'device', 0, 100);
        /** @var NewAccessToken $token */
        $token = $locked->createToken($name, [CourierAccount::ABILITY], now()->addMinutes((int) config('sanctum.expiration', 60)));

        $locked->forceFill([
            'current_token_id' => (int) $token->accessToken->id,
            'session_generation' => $locked->session_generation + 1,
        ] + ($touchLogin ? ['last_login_at' => now()] : []))->save();

        $expiresAt = $token->accessToken->expires_at;

        return [
            'token' => (string) $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_in' => $expiresAt ? $expiresAt->getTimestamp() - now()->getTimestamp() : null,
            'session' => (int) $locked->session_generation,
            'role' => CourierAccount::ABILITY,
        ];
    }
}
