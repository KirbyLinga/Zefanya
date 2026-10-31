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

    public const LICENSE_REGEX = '/^[A-Z]\d{2}-\d{2}-\d{6}$/';

    public const VEHICLES = ['Motorcycle', 'Car', 'Van'];

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Shared PH mobile normalisation (spaces removed, +639… → 09…). */
    public static function normalizeMobile(string $phone): string
    {
        return PhilippineMobile::normalize($phone);
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

    /** DOB must be ≥18y ago today (Manila); see BirthDate. */
    public static function isAdult(string $dob): bool
    {
        return BirthDate::isAdult($dob);
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
