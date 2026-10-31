<?php

namespace App\Http\Middleware\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Support\AccountUnderReview;
use App\Support\SignedOtpLink;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureLogisticsApproved
{
    /**
     * Mirror of EnsureSellerApproved for the logistics guard.
     * A logistics provider must be verified AND admin-approved before they
     * can reach logistics operations. Pending providers keep their session and
     * see a review notice; other non-approved accounts return to login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = Auth::guard('logistics')->user();

        if (! $provider) {
            return redirect()->route('home', ['login' => 1]);
        }

        if ($provider->status === LogisticsProviderStatus::Pending) {
            return AccountUnderReview::response($request);
        }

        // LogisticsProvider casts `status` to LogisticsProviderStatus.
        if ($provider->status !== LogisticsProviderStatus::Approved) {
            Auth::guard('logistics')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $redirect = redirect()->route('home', ['login' => 1])->with('auth.status_message', match ($provider->status) {
                LogisticsProviderStatus::Unverified => 'Your email has not been verified yet. Please check your email for the verification code.',
                LogisticsProviderStatus::Pending => 'Your account is still pending administrator approval.',
                LogisticsProviderStatus::Rejected => 'Your registration was not approved. Please contact support or submit a new registration.'.(filled($provider->rejection_reason) ? ' Reason: '.$provider->rejection_reason : ''),
                LogisticsProviderStatus::Suspended => 'Your logistics account has been suspended. Please contact support.',
                default => 'Your account is not active.',
            });

            // Unverified providers get a direct link to resume verification.
            if ($provider->status === LogisticsProviderStatus::Unverified) {
                $redirect->with('auth.resume_url', SignedOtpLink::showUrl('logistics', $provider));
                $redirect->with('auth.resume_label', 'Verify your email now');
            }

            return $redirect;
        }

        return $next($request);
    }
}
