<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UnifiedLoginController extends Controller
{
    /**
     * Unified login endpoint.
     *
     * Single entry point for both buyer and seller authentication.
     * The system automatically determines the user's role upon successful
     * authentication and redirects accordingly:
     *   - Seller → seller dashboard
     *   - Buyer  → buyer home
     *
     * Priority: seller guard is tried first because it is the more
     * privileged role. If a single email exists in both tables with a
     * valid password, the seller session is preferred.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email    = $credentials['email'];
        $password = $credentials['password'];
        $remember = $request->boolean('remember');

        // Try seller guard first (privileged role).
        if ($this->tryGuard('seller', $email, $password, $remember, $request)) {
            return redirect()->intended(route('seller.dashboard'));
        }

        // Fall back to buyer guard.
        if ($this->tryGuard('buyer', $email, $password, $remember, $request)) {
            return redirect()->intended(route('buyer.home'));
        }

        // Neither guard could authenticate with these credentials.
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Attempt authentication against one guard.
     *
     * Returns true only when the attempt succeeds AND the account is
     * in an approved state. On a successful attempt with a non-approved
     * account the guard is logged out so the next guard can be tried, and
     * a role-specific hint is stored for the final error response.
     */
    private function tryGuard(string $guard, string $email, string $password, bool $remember, Request $request): bool
    {
        if (! Auth::guard($guard)->attempt(['email' => $email, 'password' => $password], $remember)) {
            return false;
        }

        $user = Auth::guard($guard)->user();

        if ($user->status !== 'approved') {
            Auth::guard($guard)->logout();

            $request->session()->flash('auth.status_message', $this->statusMessage($guard, $user->status));

            return false;
        }

        $request->session()->regenerate();

        return true;
    }

    /**
     * Role-specific approval-status message.
     */
    private function statusMessage(string $guard, string $status): string
    {
        return match ([$guard, $status]) {
            ['seller', 'pending_verification'] => 'Your email has not been verified yet. Please check your email for the verification code.',
            ['seller', 'pending_approval']     => 'Your seller account is still pending administrator approval.',
            ['seller', 'rejected']             => 'Your seller registration was not approved. Please contact support or submit a new registration.',
            ['buyer', 'pending_verification']  => 'Please verify your email before logging in. Check your inbox for the code.',
            ['buyer', 'pending_approval']      => 'Your buyer registration is still awaiting administrator approval.',
            ['buyer', 'rejected']              => 'Your buyer registration was not approved.',
            default                            => 'Your account is not active.',
        };
    }

    /**
     * Log out from every guard and redirect to the home page.
     */
    public function logout(Request $request)
    {
        Auth::guard('seller')->logout();
        Auth::guard('buyer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
