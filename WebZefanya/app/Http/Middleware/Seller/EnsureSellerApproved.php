<?php

namespace App\Http\Middleware\Seller;

use App\Support\AccountUnderReview;
use App\Support\SignedOtpLink;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $seller = Auth::guard('seller')->user();

        if (! $seller) {
            return redirect()->route('home', ['login' => 1]);
        }

        if ($seller->status === 'pending') {
            return AccountUnderReview::response($request);
        }

        if ($seller->status !== 'approved') {
            Auth::guard('seller')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $redirect = redirect()->route('home', ['login' => 1])->with('auth.status_message', match ($seller->status) {
                'unverified' => 'Your email has not been verified yet. Please check your email for the verification code.',
                'pending' => 'Your account is still pending administrator approval.',
                'rejected' => 'Your registration was not approved. Please contact support or submit a new registration.'.(filled($seller->rejection_reason) ? ' Reason: '.$seller->rejection_reason : ''),
                'suspended' => 'Your seller account has been suspended. Please contact support.',
                default => 'Your account is not active.',
            });

            // Unverified accounts get a direct link to resume verification.
            if ($seller->status === 'unverified') {
                $redirect->with('auth.resume_url', SignedOtpLink::showUrl('seller', $seller));
                $redirect->with('auth.resume_label', 'Verify your email now');
            }

            return $redirect;
        }

        return $next($request);
    }
}
