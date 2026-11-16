<?php

namespace App\Support;

/**
 * Masking helpers for the printed seller waybill (public label on the parcel).
 * Digital rider waybills show full details under authorization instead.
 */
final class WaybillPrivacy
{
    public static function maskPersonName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'Buyer';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $masked = [];

        foreach ($parts as $part) {
            $len = mb_strlen($part);
            if ($len <= 1) {
                $masked[] = $part;
            } elseif ($len === 2) {
                $masked[] = mb_substr($part, 0, 1).'*';
            } else {
                $masked[] = mb_substr($part, 0, 1).str_repeat('*', max(1, $len - 2)).mb_substr($part, -1);
            }
        }

        return implode(' ', $masked);
    }

    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '—';
        }

        if (strlen($digits) < 7) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 4).' *** **'.substr($digits, -2);
    }

    /**
     * Routing-only address for the printed label (no street / house detail).
     *
     * @param  array<string, mixed>  $addr
     */
    public static function routingAddress(array $addr): string
    {
        $parts = array_filter([
            $addr['barangay'] ?? null,
            $addr['municipality'] ?? null,
            $addr['province'] ?? null,
            $addr['postal_code'] ?? null,
        ], fn ($v) => filled($v));

        return $parts === [] ? 'Destination on file' : implode(', ', $parts);
    }
}
