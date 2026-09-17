<?php

namespace App\Http\Middleware\Seller;

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
            return redirect()->route('login');
        }

        if ($seller->status !== 'approved') {
            Auth::guard('seller')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('auth.status_message', match ($seller->status) {
                'pending_verification' => 'Your email has not been verified yet. Please check your email for the verification code.',
                'pending_approval' => 'Your account is still pending administrator approval.',
                'rejected' => 'Your registration was not approved. Please contact support or submit a new registration.',
                default => 'Your account is not active.',
            });
        }

        return $next($request);
    }
}
