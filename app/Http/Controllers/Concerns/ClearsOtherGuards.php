<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuthRole;
use Illuminate\Support\Facades\Auth;

/**
 * "One account per browser": opening a session on one role's guard must end
 * every other role's session in the same browser, and logging out must clear
 * every guard (not just the one that initiated the logout).
 *
 * The browser shares a single session cookie, but each guard stores its own
 * login key inside that session, so without this a browser could hold a
 * buyer AND a seller session at once.
 */
trait ClearsOtherGuards
{
    /**
     * Log every guard out except $keep (the one being signed in).
     */
    protected function clearOtherGuards(string $keep): void
    {
        foreach (AuthRole::detectionOrder() as $role) {
            $guard = $role->guard();

            if ($guard !== $keep) {
                Auth::guard($guard)->logout();
                session()->forget('password_hash_'.$guard);
            }
        }

        $this->rememberSession($keep);
    }

    /**
     * Log every guard out (used on logout).
     */
    protected function clearAllGuards(): void
    {
        foreach (AuthRole::detectionOrder() as $role) {
            $guard = $role->guard();
            Auth::guard($guard)->logout();
            session()->forget('password_hash_'.$guard);
        }
    }

    /**
     * Remember which role this browser most recently signed in as, and store an
     * HMAC of the account's password hash so AuthenticateRoleSession can end
     * this session if the password later changes elsewhere.
     */
    protected function rememberSession(string $guard): void
    {
        session(['auth.last_guard' => $guard]);

        $user = Auth::guard($guard)->user();

        if ($user) {
            session([
                'password_hash_'.$guard => hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key')),
            ]);
        }
    }
}
