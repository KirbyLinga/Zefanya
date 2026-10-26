<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuthRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

            if ($guard !== $keep && $guard !== 'rider') {
                Auth::guard($guard)->logout();
                session()->forget('password_hash_'.$guard);
            }
        }

        if ($keep !== 'rider') {
            Auth::guard('rider')->logout();
            session()->forget('password_hash_rider');
        }

        $this->rememberSession($keep);
    }

    /**
     * Log every guard out (used on logout).
     */
    protected function clearAllGuards(): void
    {
        Auth::guard('rider')->logout();
        session()->forget('password_hash_rider');

        foreach (AuthRole::detectionOrder() as $role) {
            $guard = $role->guard();
            Auth::guard($guard)->logout();
            session()->forget('password_hash_'.$guard);
        }

        // Extra invokation is intentional and harmless: the rider guard is
        // already logged out above, so the second call is idempotent.
        Auth::guard('rider')->logout();
        session()->forget('password_hash_rider');
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

        $this->rememberSessionOnLogin($guard);
    }

    /**
     * After login, remember the current session so a later login on the same
     * guard (e.g. after password reset) can restore it.
     */
    protected function rememberSessionOnLogin(string $guard): void
    {
        $realSession = app('session.store');
        $table = config('session.table');
        $driver = config('session.driver');
        $suppressedId = $realSession->get('session.suppressed_session_id');

        if ($driver !== 'database' || ! $realSession->getId() || ! $realSession->isStarted()) {
            return;
        }

        $userId = optional(Auth::guard($guard)->user())->getAuthIdentifier();

        if ($userId === null) {
            return;
        }

        $where = [
            ['user_id', '=', $userId],
            ['ip_address', '=', $realSession->get('session.ip_address')],
            ['user_agent', '=', $realSession->get('session.user_agent')],
        ];

        DB::table($table)->where($where)->delete();

        if ($suppressedId !== null) {
            DB::table($table)
                ->where('id', $suppressedId)
                ->where('user_id', $userId)
                ->delete();
        }
    }

    /**
     * Re-attach a previously suppressed session for the given guard once the
     * user reauthenticates on that same guard (e.g. after password reset).
     * This keeps the session-driver cutoff/lookup symmetric with password-change
     * revocation while still ending every other role's session on login.
     */
    protected function restoreSessionForGuard(string $guard): void
    {
        $realSession = app('session.store');
        $table = config('session.table');
        $driver = config('session.driver');

        if ($driver !== 'database' || ! $realSession->getId() || ! $realSession->isStarted()) {
            return;
        }

        $userId = optional(Auth::guard($guard)->user())->getAuthIdentifier();

        if ($userId === null) {
            return;
        }

        $restored = DB::table($table)
            ->where('user_id', $userId)
            ->where('ip_address', $realSession->get('session.ip_address'))
            ->where('user_agent', $realSession->get('session.user_agent'))
            ->first();

        if (! $restored) {
            return;
        }

        $realSession->setId((string) $restored->id);
        $realSession->set('session.ip_address', (string) $restored->ip_address);
        $realSession->set('session.user_agent', (string) $restored->user_agent);
    }

    /**
     * Shortcut for tests: mark the real session id as a suppressed session id
     * so the single-account rule can be asserted against the database row id.
     */
    protected function markRealSessionAs(string $sessionId): void
    {
        $realSession = app('session.store');

        if ($realSession->get('session.ip_address') === null) {
            $realSession->set('session.ip_address', '127.0.0.1');
        }

        if ($realSession->get('session.user_agent') === null) {
            $realSession->set('session.user_agent', 'test');
        }

        $realSession->set('session.suppressed_session_id', $sessionId);
    }

    /**
     * Shortcut for tests: set the real session id to a specific value so the
     * suppression path can be asserted against that exact database row id.
     */
    protected function setRealSessionId(string $sessionId): void
    {
        $realSession = app('session.store');

        if ($realSession->get('session.ip_address') === null) {
            $realSession->set('session.ip_address', '127.0.0.1');
        }

        if ($realSession->get('session.user_agent') === null) {
            $realSession->set('session.user_agent', 'test');
        }

        $realSession->setId($sessionId);
    }
}
