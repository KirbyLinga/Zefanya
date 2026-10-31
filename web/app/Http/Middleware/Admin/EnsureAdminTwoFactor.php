<?php

namespace App\Http\Middleware\Admin;

use App\Models\Admin\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional enforcement of 2FA enrolment for the admin console.
 *
 * When admin.require_two_factor is true, an authenticated admin who has not
 * completed authenticator enrolment is redirected to the setup screen. The
 * challenge itself is enforced by the login flow for every enrolled admin.
 */
class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin || ! config('admin.require_two_factor')) {
            return $next($request);
        }

        if ($admin->hasEnabledTwoFactor() || $request->routeIs('admin.two-factor.*')) {
            return $next($request);
        }

        return redirect()
            ->route('admin.two-factor.show')
            ->with('auth.status_message', 'Two-factor authentication is required. Set up your authenticator to continue.');
    }
}
