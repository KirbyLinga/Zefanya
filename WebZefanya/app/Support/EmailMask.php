<?php

namespace App\Support;

/**
 * Masks an email address for display on OTP / verification surfaces.
 *
 * `juan.dela.cruz@example.com` → `j***@example.com`. The full address must
 * never be rendered where a stranger could read it from the screen (or the
 * page source) after changing the id in a verification URL.
 */
class EmailMask
{
    public static function mask(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return '';
        }

        $at = strrpos($email, '@');

        // No usable local part — show an opaque placeholder instead.
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        return mb_substr($local, 0, 1).'***'.$domain;
    }
}
