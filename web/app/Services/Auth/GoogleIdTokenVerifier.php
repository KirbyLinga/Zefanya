<?php

namespace App\Services\Auth;

interface GoogleIdTokenVerifier
{
    /**
     * Verify a Google ID token and return normalized claims.
     *
     * Implementations must check Google's signature, the issuer, expiry and
     * that the audience is one of the configured client IDs. They must never
     * return claims they could not verify.
     *
     * @return array{
     *     sub: string,
     *     email: string,
     *     email_verified: bool,
     *     name: string,
     *     given_name?: string|null,
     *     family_name?: string|null,
     *     picture?: string|null,
     *     iss: string,
     *     aud: string,
     *     exp: int
     * }
     *
     * @throws \Throwable
     */
    public function verify(string $idToken): array;
}
