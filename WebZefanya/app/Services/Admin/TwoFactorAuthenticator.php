<?php

namespace App\Services\Admin;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;

/**
 * RFC 6238 TOTP authenticator for ADMIN accounts only.
 *
 * Deliberately self-contained (no new dependency): SHA-1, 30-second period,
 * 6 digits, one period of clock skew. Secrets and recovery-code hashes are
 * stored encrypted by the Admin model; raw secrets are never logged.
 */
class TwoFactorAuthenticator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** 160 bits, the RFC 4226 recommendation. */
    private const SECRET_BYTES = 20;

    /** Allow one period of clock drift either way. */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        $secret = strtoupper(trim($secret));

        if ($secret === '') {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Current code — used by tests and enrolment verification. */
    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        return $this->codeAt(strtoupper(trim($secret)), intdiv($timestamp ?? time(), self::PERIOD));
    }

    public function provisioningUri(string $secret, string $accountEmail, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$accountEmail);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    public function qrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 4),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($uri);
    }

    /**
     * Human-readable recovery codes. Only hashes are persisted.
     *
     * @return array{plain: list<string>, hashed: list<string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $plain[] = substr($raw, 0, 4).'-'.substr($raw, 4);
        }

        $hashed = array_map(fn (string $code): string => Hash::make($this->normalizeRecoveryCode($code)), $plain);

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /**
     * Return the index of the matching unused recovery code, or null.
     *
     * @param  list<string>  $hashes
     */
    public function matchRecoveryCode(string $code, array $hashes): ?int
    {
        $normalized = $this->normalizeRecoveryCode($code);

        if ($normalized === '') {
            return null;
        }

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                return $index;
            }
        }

        return null;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);

        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }

        // 8-byte big-endian counter, as required by RFC 4226.
        $binary = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($secret) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }

            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }
}
