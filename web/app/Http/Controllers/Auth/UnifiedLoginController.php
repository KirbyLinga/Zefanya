<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Support\EmailRegistry;
use App\Support\SignedOtpLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UnifiedLoginController extends Controller
{
    use ClearsOtherGuards;

    /**
     * Unified login endpoint.
     *
     * Single entry point for buyer, seller, and logistics provider authentication.
     * The system automatically determines the user's role upon successful
     * authentication and redirects accordingly:
     *   - Seller    â†’ seller dashboard
     *   - Logistics â†’ logistics dashboard
     *   - Buyer     â†’ buyer home
     *
     * Priority: seller guard is tried first because it is the most
     * privileged role. Logistics is tried next, then buyer. If a single
     * email exists in multiple tables with a valid password, the more
     * privileged session is preferred.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = $credentials['email'];
        $password = $credentials['password'];
        $remember = $request->boolean('remember');

        // Try seller guard first (privileged role).
        if ($this->tryGuard('seller', $email, $password, $remember, $request)) {
            return redirect()->intended(route('seller.dashboard'));
        }

        // Fall back to logistics provider.
        if ($this->tryGuard('logistics', $email, $password, $remember, $request)) {
            return redirect()->intended(route('logistics.dashboard'));
        }

        // Fall back to buyer guard.
        if ($this->tryGuard('buyer', $email, $password, $remember, $request)) {
            return redirect()->intended(route('buyer.home'));
        }

        if ($this->tryGuard('rider', strtolower(trim($email)), $password, $remember, $request)) {
            return redirect()->intended(route('rider.dashboard'));
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
        if ($guard === 'rider' && ! Schema::hasTable('rider_applications')) {
            return false;
        }
        if (! Auth::guard($guard)->attempt(['email' => $email, 'password' => $password], $remember)) {
            return false;
        }

        $user = Auth::guard($guard)->user();

        // Status may be a plain string (Buyer/Seller) or a backed enum object
        // (LogisticsProvider, which casts status to LogisticsProviderStatus).
        $status = match (true) {
            is_string($user->status) => $user->status,
            $user->status instanceof \BackedEnum => $user->status->value,
            default => '',
        };

        if ($status !== 'approved' || ($guard === 'rider' && ! $user->isApproved())) {
            Auth::guard($guard)->logout();

            $request->session()->flash('auth.status_message', $this->statusMessage($guard, $status));
            if ($guard === 'rider' && $status === 'unverified') {
                $request->session()->flash('auth.resume_url', SignedOtpLink::showUrl('rider', $user));
                $request->session()->flash('auth.resume_label', 'Verify your email now');
            }

            return false;
        }

        $roles = EmailRegistry::rolesFor($email);
        if (count($roles) > 1 && in_array('rider', $roles, true)) {
            Auth::guard($guard)->logout();
            throw ValidationException::withMessages(['email' => 'This email belongs to more than one account type. Please contact support.']);
        }
        $this->clearOtherGuards($guard);
        $request->session()->regenerate();

        return true;
    }

    /**
     * Role-specific approval-status message.
     */
    private function statusMessage(string $guard, mixed $status): string
    {
        $status = is_scalar($status) ? (string) $status : '';

        return match ([$guard, $status]) {
            ['seller', 'pending_verification'] => 'Your email has not been verified yet. Please check your email for the verification code.',
            ['seller', 'pending_approval'] => 'Your seller account is still pending administrator approval.',
            ['seller', 'rejected'] => 'Your seller registration was not approved. Please contact support or submit a new registration.',
            ['logistics', 'pending_verification'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['logistics', 'pending_approval'] => 'Your logistics provider registration is still awaiting administrator approval.',
            ['logistics', 'rejected'] => 'Your logistics provider registration was not approved.',
            ['buyer', 'pending_verification'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['buyer', 'pending_approval'] => 'Your buyer registration is still awaiting administrator approval.',
            ['buyer', 'rejected'] => 'Your buyer registration was not approved.',
            ['rider', 'unverified'] => 'Please verify your Rider email before logging in.',
            ['rider', 'pending'] => 'Your Rider application is pending Logistics approval.',
            ['rider', 'rejected'] => 'Your Rider application was rejected by Logistics.',
            ['rider', 'suspended'] => 'Your Rider account has been suspended.',
            default => 'Your account is not active.',
        };
    }

    /**
     * Log out from every guard and redirect to the home page.
     */
    public function logout(Request $request)
    {
        $this->clearAllGuards();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
