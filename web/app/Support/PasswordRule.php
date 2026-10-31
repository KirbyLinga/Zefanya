<?php

namespace App\Support;

use Closure;

/**
 * Shared password rule for every role. Mirrors getPasswordStrength() and the
 * "password" validator in resources/js/registration-validation.js: at least 8
 * characters with an uppercase letter, a lowercase letter, a number and a
 * special character. The message names every rule the password fails.
 */
class PasswordRule
{
    public const MIN = 8;

    /** bcrypt only uses the first 72 bytes; longer passwords are refused, never truncated. */
    public const MAX_BYTES = 72;

    public const MISMATCH_MESSAGE = 'Passwords do not match.';

    /** @var array<string, string> */
    private const TYPES = [
        'an uppercase letter' => '/\p{Lu}/u',
        'a lowercase letter' => '/\p{Ll}/u',
        'a number' => '/\p{N}/u',
        'a special character' => '/[\p{Z}\p{S}\p{P}]/u',
    ];

    /** @return list<string> */
    public static function missingTypes(string $password): array
    {
        $missing = [];
        foreach (self::TYPES as $label => $pattern) {
            if (preg_match($pattern, $password) !== 1) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** Null when the password meets every rule; otherwise one message naming each failing rule. */
    public static function error(string $password): ?string
    {
        if (strlen($password) > self::MAX_BYTES) {
            return 'Password must not exceed '.self::MAX_BYTES.' bytes.';
        }

        $short = mb_strlen($password) < self::MIN;
        $missing = self::missingTypes($password);

        if (! $short && $missing === []) {
            return null;
        }

        $include = $missing === [] ? '' : 'include '.self::join($missing);

        if (! $short) {
            return 'Password must '.$include.'.';
        }

        return 'Password must be at least '.self::MIN.' characters'.($include === '' ? '' : ' and '.$include).'.';
    }

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || str_contains($value, "\0")) {
                    $fail('Enter a valid password.');

                    return;
                }
                if (($error = self::error($value)) !== null) {
                    $fail($error);
                }
            },
            'confirmed',
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'password'): array
    {
        return [$field.'.confirmed' => self::MISMATCH_MESSAGE];
    }

    /** @param  list<string>  $items */
    private static function join(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
