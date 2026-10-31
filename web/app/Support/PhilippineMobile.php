<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared Philippine mobile rule for every role. Mirrors normalizeMobile() in
 * resources/js/registration-validation.js: 09XXXXXXXXX or +639XXXXXXXXX,
 * spaces ignored, stored as 09XXXXXXXXX, and one number per account across
 * buyers, sellers, logistics providers and riders.
 */
class PhilippineMobile
{
    public const FORMAT_MESSAGE = 'Enter a valid mobile number (09XXXXXXXXX or +639XXXXXXXXX).';

    public const LENGTH_MESSAGE = 'Mobile number must be 11 digits, like 09171234567.';

    public const LENGTH_MESSAGE_INTL = 'Enter +63 followed by 10 digits, like +639171234567.';

    public const TAKEN_MESSAGE = 'This mobile number is already in use.';

    /** Account tables that own a contact_no; the first three archive old applicants. */
    private const TABLES = [
        'buyer' => 'buyers',
        'seller' => 'sellers',
        'logistics' => 'logistics_providers',
        'rider' => 'riders',
    ];

    /** Remove spaces and turn +639XXXXXXXXX into 09XXXXXXXXX; anything else is returned for validation to reject. */
    public static function normalize(string $phone): string
    {
        $phone = (string) preg_replace('/\s+/u', '', $phone);

        return preg_match('/^\+639\d{9}$/', $phone) === 1 ? '0'.substr($phone, 3) : $phone;
    }

    /** Same checks and messages as the browser, applied to the raw or normalised value. */
    public static function error(string $phone): ?string
    {
        $phone = (string) preg_replace('/\s+/u', '', $phone);

        if (preg_match('/^\+?\d+$/', $phone) !== 1) {
            return self::FORMAT_MESSAGE;
        }

        if (str_starts_with($phone, '+')) {
            if (! str_starts_with($phone, '+63')) {
                return self::FORMAT_MESSAGE;
            }
            $rest = substr($phone, 3);
            if (strlen($rest) !== 10) {
                return self::LENGTH_MESSAGE_INTL;
            }

            return $rest[0] === '9' ? null : self::FORMAT_MESSAGE;
        }

        if (! str_starts_with($phone, '09')) {
            return self::FORMAT_MESSAGE;
        }

        return strlen($phone) === 11 ? null : self::LENGTH_MESSAGE;
    }

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ($error = self::error($value)) !== null) {
                    $fail($error ?? self::FORMAT_MESSAGE);
                }
            },
        ];
    }

    /**
     * True when another live account already owns this (normalised) number.
     * For buyer/seller/logistics registration, the applicant's own
     * unverified/rejected row with the same email is ignored: store() archives
     * it and clears its contact_no before the new row is written.
     */
    public static function isTaken(string $phone, string $ownRole, ?string $email = null): bool
    {
        foreach (self::TABLES as $role => $table) {
            $query = DB::table($table)->where('contact_no', $phone);

            if ($role !== 'rider') {
                $query->whereNull('archived_at');
            }

            if ($role === $ownRole && $role !== 'rider' && $email !== null) {
                $query->where(function ($query) use ($email): void {
                    $query->whereRaw('LOWER(email) != ?', [EmailRegistry::normalize($email)])
                        ->orWhereNotIn('status', ['unverified', 'rejected']);
                });
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
