<?php

namespace App\Http\Middleware\Buyer;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational gate for every Buyer panel route.
 *
 * A buyer may only use the authenticated buyer area while their account is
 * approved. Suspending or otherwise moving a buyer out of approved status
 * therefore revokes web access on the very next request — not just future
 * orders.
 */
class EnsureBuyerActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $buyer = Auth::guard('buyer')->user();

        if (! $buyer) {
            return redirect()->route('home', ['login' => 1]);
        }

        if ($buyer->status !== 'approved') {
            // End the authenticated session for this guard.
            Auth::guard('buyer')->logout();

            // Destroy the current session payload + CSRF token. We do not
            // regenerate the whole session ID here: this middleware runs inside
            // the shared web stack (after EnforceSingleBrowserAccount and
            // AuthenticateRoleSession), and a session-regenerate here would both
            // re-issue the session cookie and leave other-role session payloads
            // (if any) untouched. The important part for suspension is that this
            // buyer's current request can no longer authenticate.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Rotate the remember-me token so an outstanding "remember me"
            // cookie no longer corresponds to a valid remember token in the
            // database. This ends persistent sessions that were started with
            // remember_me, without waiting for the session to expire.
            $buyer->setRememberToken(null);

            return redirect()->route('home', ['login' => 1])->with('auth.status_message', match ($buyer->status) {
                'unverified' => 'Your email has not been verified yet. Please check your email for the verification code.',
                'pending' => 'Your account is still pending administrator approval.',
                'rejected' => 'Your registration was not approved. Please contact support or submit a new registration.',
                'suspended' => 'Your buyer account has been suspended. Please contact support.',
                default => 'Your account is not active.',
            });

        }

        return $next($request);
    }
}
