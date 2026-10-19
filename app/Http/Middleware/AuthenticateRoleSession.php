<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends every OTHER session still holding an account's old password.
 *
 * Laravel's built-in AuthenticateSession middleware only understands the default
 * guard, so this is the four-guard version the app needs. At login,
 * ClearsOtherGuards stores an HMAC of the account's current password hash in
 * the session. On every request the stored value is compared with the live
 * hash: a mismatch means the password was changed somewhere else (typically a
 * password reset), so this session is logged out.
 */
class AuthenticateRoleSession
{
    /**
     * @var list<string>
     */
    private const GUARDS = ['admin', 'seller', 'logistics', 'buyer'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::GUARDS as $guard) {
            $user = Auth::guard($guard)->user();

            if (! $user) {
                continue;
            }

            $expected = $this->fingerprint((string) $user->getAuthPassword());
            $hashKey = 'password_hash_'.$guard;
            $userKey = 'password_hash_user_'.$guard;
            $stored = $request->session()->get($hashKey);
            $storedUserId = $request->session()->get($userKey);
            $userId = $user->getAuthIdentifier();

            // Only treat a fingerprint mismatch as "password changed" when it
            // belongs to the SAME account. A different user on the same guard
            // (tests, or a rare same-browser re-login) just refreshes the stamp.
            if (
                $stored !== null
                && (string) $storedUserId === (string) $userId
                && ! hash_equals($expected, (string) $stored)
            ) {
                Auth::guard($guard)->logout();
                $request->session()->forget([$hashKey, $userKey]);

                continue;
            }

            // Seed / refresh so a future password change on this account is detected.
            $request->session()->put($hashKey, $expected);
            $request->session()->put($userKey, $userId);
        }

        return $next($request);
    }

    private function fingerprint(string $passwordHash): string
    {
        return hash_hmac('sha256', $passwordHash, (string) config('app.key'));
    }
}
