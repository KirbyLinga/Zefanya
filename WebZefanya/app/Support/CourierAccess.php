<?php

namespace App\Support;

use App\Services\Logistics\TransferCourierService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Readiness and runtime gate for Courier Mobile app access.
 *
 *  - schemaReady(): the courier_accounts table (from a pending migration) is
 *    fully present. When false, nothing in the app queries it, so existing
 *    Courier management behaves exactly as before.
 *  - enabled(): schema ready AND config('courier.enabled') AND a valid,
 *    non-future config('courier.enabled_since'). Courier sign-in, activation,
 *    refresh, reads and access issuing need this. Security revocation only
 *    needs schemaReady(), so access can always be removed.
 */
final class CourierAccess
{
    private static ?bool $schemaReady = null;

    public static function schemaReady(): bool
    {
        return self::$schemaReady ??= TransferCourierService::available()
            && Schema::hasTable('courier_accounts')
            && Schema::hasColumn('courier_accounts', 'session_generation');
    }

    public static function enabled(): bool
    {
        return config('courier.enabled') === true
            && self::enabledSince() !== null
            && self::schemaReady();
    }

    /** The start of the current enabled period, or null when missing, invalid or in the future. */
    public static function enabledSince(): ?CarbonImmutable
    {
        $value = config('courier.enabled_since');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $since = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $since->isFuture() ? null : $since;
    }

    /** Forget the memoized schema probe (tests and post-migration checks). */
    public static function flush(): void
    {
        self::$schemaReady = null;
    }
}
