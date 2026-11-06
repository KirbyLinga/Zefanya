<?php

namespace App\Http\Middleware\Rider;

use App\Models\Rider\Rider;
use App\Support\AccountUnderReview;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational gate for every Rider page.
 *
 * A Rider may only work while Logistics has them approved/active, their email
 * is verified, and their assigned Station Hub is approved, active and owned by
 * the same company, and the company itself is approved. Suspending a rider or
 * their company therefore revokes web access on the very next request — not
 * just future assignments — unless Admin granted the company a wind-down.
 */
class EnsureRiderActive
{
    public const WIND_DOWN_MESSAGE = 'Your logistics company is suspended. You can only finish deliveries that are already out for delivery.';

    /** Pages a wind-down rider needs to deliver, fail or report a parcel already in hand. */
    private const WIND_DOWN_ROUTES = [
        'rider.deliveries.index',
        'rider.deliveries.proof',
        'rider.deliveries.otp',
        'rider.deliveries.deliver',
        'rider.deliveries.failed.form',
        'rider.deliveries.failed',
        'rider.scan.index',
        'rider.scan.verify',
        'rider.waybills.show',
        'rider.proofs.show',
        'rider.money.index',
        'rider.account.show',
        'rider.notifications.*',
        'parcels.incidents.store',
        'api.rider.parcels.incidents.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $rider = Auth::guard('rider')->user();

        if (! $rider) {
            return redirect()->route('home', ['login' => 1]);
        }

        if ($rider->status === Rider::STATUS_PENDING) {
            return AccountUnderReview::response($request);
        }

        $rider->loadMissing(['station', 'courier', 'provider']);

        if ($rider->isOperational()) {
            return $next($request);
        }

        // Suspended company inside an Admin-granted wind-down: only the pages
        // needed to finish a parcel already out for delivery stay open.
        if ($rider->isInWindDown()) {
            if ($request->routeIs(...self::WIND_DOWN_ROUTES)) {
                return $next($request);
            }

            return redirect()->route('rider.deliveries.index')
                ->with('error', self::WIND_DOWN_MESSAGE);
        }

        Auth::guard('rider')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home', ['login' => 1])
            ->with('auth.status_message', 'Your rider account is not active yet. Please contact your logistics company.');
    }
}
