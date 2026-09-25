<?php

namespace App\Http\Middleware\Logistics;

use App\Enums\LogisticsProviderStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureLogisticsApproved
{
    /**
     * Mirror of EnsureSellerApproved for the logistics guard.
     * A logistics provider must be verified AND admin-approved before they
     * can reach any logistics-panel route. Pending/non-approved providers are
     * logged out and bounced to the login screen with a contextual message.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = Auth::guard('logistics')->user();

        if (! $provider) {
            return redirect()->route('login');
        }

        // LogisticsProvider casts `status` to LogisticsProviderStatus.
        if ($provider->status !== LogisticsProviderStatus::Approved) {
            Auth::guard('logistics')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('auth.status_message', match ($provider->status) {
                LogisticsProviderStatus::PendingVerification => 'Your email has not been verified yet. Please check your email for the verification code.',
                LogisticsProviderStatus::PendingApproval => 'Your account is still pending administrator approval.',
                LogisticsProviderStatus::Rejected => 'Your registration was not approved. Please contact support or submit a new registration.',
                default => 'Your account is not active.',
            });
        }

        return $next($request);
    }
}
