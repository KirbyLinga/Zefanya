<?php

namespace App\Support;

/**
 * Shared first/last name rule for every role (Buyer, Seller, Logistics, Rider).
 * Mirrors NAME_RE in resources/js/registration-validation.js so the browser and
 * the server accept exactly the same names. Values are stored as typed.
 */
class PersonName
{
    /** Starts with a letter; letters (incl. ñ), spaces, hyphen, apostrophe and period; no trailing separator. */
    public const REGEX = '/^\p{L}(?:[\p{L}\p{M} \'’.\-]*[\p{L}\p{M}.])?$/u';

    public const MIN = 2;

    public const MAX = 50;

    /** @return array<int, string> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN, 'max:'.self::MAX, 'regex:'.self::REGEX];
    }

    /** @return array<string, string> */
    public static function messages(string $field, string $label): array
    {
        return [
            $field.'.regex' => $label.' can only contain letters, spaces, hyphens, apostrophes and periods.',
            $field.'.min' => $label.' must be at least '.self::MIN.' characters.',
            $field.'.max' => $label.' must not exceed '.self::MAX.' characters.',
        ];
    }
}
