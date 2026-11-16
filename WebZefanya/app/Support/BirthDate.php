<?php

namespace App\Support;

use Carbon\Carbon;
use Closure;

/**
 * Shared date-of-birth rule for every role. Mirrors checkBirthdate() in
 * resources/js/registration-validation.js: no future dates, and the person
 * must be at least 18 on today's date in Asia/Manila (never app UTC), so a
 * person who turns 18 today is accepted all day long.
 */
class BirthDate
{
    public const MIN_AGE = 18;

    public const INVALID_MESSAGE = 'Please enter a valid date of birth.';

    public const FUTURE_MESSAGE = 'Please enter a valid date of birth. It cannot be in the future.';

    public const UNDER_AGE_MESSAGE = 'You must be at least 18 years old.';

    public static function error(string $value): ?string
    {
        try {
            $birth = Carbon::parse($value, RiderValidation::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return self::INVALID_MESSAGE;
        }

        $today = RiderValidation::today();

        if ($birth->greaterThan($today)) {
            return self::FUTURE_MESSAGE;
        }

        return $birth->copy()->addYears(self::MIN_AGE)->lessThanOrEqualTo($today) ? null : self::UNDER_AGE_MESSAGE;
    }

    public static function isAdult(string $value): bool
    {
        return self::error($value) === null;
    }

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'bail',
            'date',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (($error = self::error((string) $value)) !== null) {
                    $fail($error);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'birthday'): array
    {
        return [$field.'.date' => self::INVALID_MESSAGE];
    }
}
