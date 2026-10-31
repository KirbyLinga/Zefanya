<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuthRole;
use App\Services\Shared\AccountSessionService;
use Illuminate\Database\Query\Builder;
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
                'password_hash_user_'.$guard => $user->getAuthIdentifier(),
            ]);
        }

        $this->rememberSessionOnLogin($guard);
    }

    /**
     * After login, remember which browser this session belongs to and end any
     * earlier database session this account left behind in the same browser.
     *
     * The fingerprint is the request's IP + User-Agent. It is persisted in the
     * session so a later login from the same browser compares against the same
     * values, and falls back to the live request when no fingerprint is stored
     * yet (the normal production case).
     */
    protected function rememberSessionOnLogin(string $guard): void
    {
        $realSession = app('session.store');
        $driver = config('session.driver');
        $suppressedId = $realSession->get('session.suppressed_session_id');

        if ($driver !== 'database' || ! $realSession->getId() || ! $realSession->isStarted()) {
            return;
        }

        $userId = optional(Auth::guard($guard)->user())->getAuthIdentifier();

        if ($userId === null) {
            return;
        }

        $ip = (string) ($realSession->get('session.ip_address') ?? request()->ip());
        $userAgent = (string) ($realSession->get('session.user_agent') ?? request()->userAgent());

        if ($ip === '' || $userAgent === '') {
            return;
        }

        $realSession->put('session.ip_address', $ip);
        $realSession->put('session.user_agent', $userAgent);

        $currentId = (string) $realSession->getId();

        app(AccountSessionService::class)->revoke($guard, $userId, function (Builder $query) use ($ip, $userAgent, $currentId): void {
            $query->where('ip_address', $ip)
                ->where('user_agent', $userAgent)
                ->when($currentId !== '', fn ($query) => $query->where('id', '!=', $currentId));
        });

        if ($suppressedId !== null) {
            app(AccountSessionService::class)->revoke($guard, $userId, function (Builder $query) use ($suppressedId): void {
                $query->where('id', $suppressedId);
            });
        }
    }
}
