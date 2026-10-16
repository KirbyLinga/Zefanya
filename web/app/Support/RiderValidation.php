<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Shared rider-registration validation (Pass 3).
 * Single source of truth for Add Rider + activation rules.
 * All date logic uses Asia/Manila, never app UTC.
 */
class RiderValidation
{
    public const TIMEZONE = 'Asia/Manila';

    public const NAME_REGEX = '/^[\p{L} .\'-]+$/u';

    public const LICENSE_REGEX = '/^[A-Z]\d{2}-\d{2}-\d{6}$/';

    public const VEHICLES = ['Motorcycle', 'Car', 'Van'];

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Strip spaces/hyphens/parens; +63→09. */
    public static function normalizeMobile(string $phone): string
    {
        $phone = (string) preg_replace('/[\s\-()]/u', '', trim($phone));
        if (str_starts_with($phone, '+63')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }

    public static function normalizePlate(string $plate): string
    {
        return strtoupper(trim($plate));
    }

    public static function normalizeLicense(string $license): string
    {
        return strtoupper(trim($license));
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    /** DOB must be ≥18y ago today (Manila). Leap-day: Carbon handles anniversary. */
    public static function isAdult(string $dob): bool
    {
        try {
            $birth = Carbon::parse($dob, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return false;
        }
        $today = self::today();
        if ($birth->greaterThan($today)) {
            return false;
        }

        return $birth->copy()->addYears(18)->lessThanOrEqualTo($today);
    }

    /** Expiry must be today or later (Manila). */
    public static function isExpiryValid(string $expiry): bool
    {
        try {
            $exp = Carbon::parse($expiry, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        return $exp->greaterThanOrEqualTo(self::today());
    }
}
