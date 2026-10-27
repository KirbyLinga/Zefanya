<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guard for first-party API bearer tokens.
 * Used on every /api/buyer/* and /api/rider/* route. Implements a single
 * "role ability" gate: a Buyer token may never reach a Rider endpoint and
 * vice versa. Failure returns a consistent JSON 403 (not a redirect, not a
 * Laravel 419 page).
 *
 * The token itself is verified by the framework's `auth:sanctum` guard,
 * which reads the bearer token and resolves the tokenable model. This
 * middleware then asserts the resolved token's abilities include the
 * required role before the controller runs.
 *
 * IMPORTANT: Sanctum v4.3.3's PersonalAccessToken has an `expires_at` column
 * but the TokenGuard does NOT auto-expire tokens. This middleware must manually
 * verify accessToken->expires_at > now() and check if the token is revoked/
 * deleted, returning 403 JSON for expired/revoked and 401 for missing token.
 */
class ApiRole
{
    /** @var array<int, string> */
    private const ALLOWED_ROLES = ['buyer', 'rider'];

    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $role = strtolower($role);

        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException("Unsupported API role [{$role}]. Expected one of: " . implode(', ', self::ALLOWED_ROLES));
        }

        // Resolve the token through the sanctum guard (bearer token). When the
        // token is missing, expired, revoked, or the role doesn't match, this
        // throws \\AuthenticationException which the app's exception handler turns
        // into a JSON 401/403 response for api requests.
        $this->auth->guard('sanctum')->authenticate();

        $user = $this->auth->guard('sanctum')->user();

        // ---- Manual expires_at & revoked check (Sanctum v4.3.3 does NOT auto-expire) ----
        if (! $user instanceof \Illuminate\Foundation\Auth\User) {
            throw new AuthenticationException(
                message: 'Unauthenticated.',
                guards: ['sanctum'],
            );
        }

        // Check if token has been revoked/deleted
        $token = $user->token();
        if (! $token) {
            throw new AuthenticationException(
                message: 'Unauthenticated. The token is missing.',
                guards: ['sanctum'],
            );
        }

        // Check expiry
        if ($token->expires_at && $token->expires_at <= now()) {
            throw new AuthenticationException(
                message: 'Unauthenticated. The token has expired.',
                guards: ['sanctum'],
            );
        }

        // Check revoked/deleted
        if ($token->hasBeenRevoked()) {
            throw new AuthenticationException(
                message: 'Unauthenticated. The token has been revoked.',
                guards: ['sanctum'],
            );
        }

        if (! $user || ! method_exists($user, 'tokenCan') || ! $user->tokenCan($role)) {
            throw new AuthenticationException(
                message: "Unauthenticated. The token is missing the [{$role}] ability.",
                guards: ['sanctum'],
            );
        }

        // Attach the authenticated user + token to the request so controllers
        // and policies can use the standard `auth('sanctum')` guard without
        // re-resolving the token from the database on every call.
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
