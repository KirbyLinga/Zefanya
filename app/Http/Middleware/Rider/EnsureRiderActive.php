<?php

namespace App\Http\Middleware\Rider;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational gate for every Rider page.
 *
 * A Rider may only work while Logistics has them approved/active, their email
 * is verified, and their assigned Station Hub is approved, active and owned by
 * the same company. Suspending or deactivating a rider therefore revokes web
 * access on the very next request — not just future assignments.
 */
class EnsureRiderActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $rider = Auth::guard('rider')->user();

        if (! $rider) {
            return redirect()->route('home', ['login' => 1]);
        }

        $rider->loadMissing(['station', 'courier']);

        if (! $rider->isOperational()) {
            Auth::guard('rider')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home', ['login' => 1])
                ->with('auth.status_message', 'Your rider account is not active yet. Please contact your logistics company.');
        }

        return $next($request);
    }
}
