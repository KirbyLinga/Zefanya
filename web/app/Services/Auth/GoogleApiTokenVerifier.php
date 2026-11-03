<?php

namespace App\Services\Auth;

use Google\Client;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * H2: verifies Google ID tokens with google/apiclient's Client.
 *
 * `Client::verifyIdToken()` checks the signature against Google's published
 * keys. This class then enforces the issuer, audience, expiry and the identity
 * claims. There is no tokeninfo HTTP fallback and no "trust the payload in
 * testing" branch: an unverified payload is never accepted.
 */
class GoogleApiTokenVerifier implements GoogleIdTokenVerifier
{
    private const string INVALID = 'Invalid or unverified Google token.';

    /** @var list<string> */
    private array $clientIds;

    public function __construct()
    {
        $raw = config('services.google.client_ids', '');

        $this->clientIds = is_array($raw)
            ? array_values(array_filter(array_map(
                fn ($id): string => trim((string) $id),
                $raw,
            ), fn (string $id): bool => $id !== ''))
            : array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $raw),
            ), fn (string $id): bool => $id !== ''));
    }

    public function verify(string $idToken): array
    {
        if ($this->clientIds === []) {
            throw new InvalidArgumentException('Google sign-in is not configured.');
        }

        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new InvalidArgumentException('Google ID token is required.');
        }

        $client = new Client();
        try {
            // The client fetches Google's certificates and verifies the JWT
            // signature. It does not need a client secret for an ID token.
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable $e) {
            Log::warning('Google ID token verification failed.', [
                'reason' => 'signature_or_network',
                'exception' => $e::class,
            ]);

            throw new InvalidArgumentException(self::INVALID);
        }

        if (! is_array($payload)) {
            Log::warning('Google ID token verification returned no payload.');

            throw new InvalidArgumentException(self::INVALID);
        }

        $issuer = $payload['iss'] ?? null;
        if ($issuer !== 'https://accounts.google.com' && $issuer !== 'accounts.google.com') {
            Log::warning('Google ID token rejected.', ['reason' => 'wrong_issuer']);

            throw new InvalidArgumentException(self::INVALID);
        }

        $audience = $payload['aud'] ?? null;
        if (! is_string($audience) || ! in_array($audience, $this->clientIds, true)) {
            Log::warning('Google ID token rejected.', ['reason' => 'wrong_audience']);

            throw new InvalidArgumentException(self::INVALID);
        }

        $expires = $payload['exp'] ?? null;
        if (! is_numeric($expires) || (int) $expires <= time()) {
            Log::warning('Google ID token rejected.', ['reason' => 'expired']);

            throw new InvalidArgumentException(self::INVALID);
        }

        // Google returns a boolean; the string "true" is not proof.
        if (($payload['email_verified'] ?? null) !== true) {
            Log::warning('Google ID token rejected.', ['reason' => 'email_not_verified']);

            throw new InvalidArgumentException(self::INVALID);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $sub = (string) ($payload['sub'] ?? '');
        if ($email === '' || $sub === '') {
            Log::warning('Google ID token rejected.', ['reason' => 'missing_email_or_sub']);

            throw new InvalidArgumentException(self::INVALID);
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'name' => trim((string) ($payload['name'] ?? $payload['given_name'] ?? 'Google User')),
            'given_name' => isset($payload['given_name']) ? (string) $payload['given_name'] : null,
            'family_name' => isset($payload['family_name']) ? (string) $payload['family_name'] : null,
            'picture' => isset($payload['picture']) ? (string) $payload['picture'] : null,
            'iss' => (string) $issuer,
            'aud' => $audience,
            'exp' => (int) $expires,
        ];
    }
}
