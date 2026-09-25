<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * The seller login page has been folded into the unified login entry
     * point (/login). Anything still pointing at /seller/login is forwarded
     * there so there is exactly one login screen in the application.
     */
    public function showLogin()
    {
        return redirect()->route('login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('seller')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $seller = Auth::guard('seller')->user();

        if ($seller->status !== 'approved') {
            Auth::guard('seller')->logout();

            throw ValidationException::withMessages([
                'email' => match ($seller->status) {
                    'pending_verification' => 'Your email has not been verified yet. Please check your email for the verification code.',
                    'pending_approval' => 'Your account is still pending administrator approval.',
                    'rejected' => 'Your registration has been rejected. Please contact support or submit a new registration.',
                    default => 'Your account is not active.',
                },
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('seller.dashboard'));
    }

    /**
     * Legacy seller-only logout.
     *
     * The seller sidebar now posts to /logout (unified.logout), which clears
     * every guard. This handler stays registered so old bookmarks keep
     * working, but it must never send the user back to a login screen —
     * logout always lands on the public home page.
     */
    public function logout(Request $request)
    {
        Auth::guard('seller')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
