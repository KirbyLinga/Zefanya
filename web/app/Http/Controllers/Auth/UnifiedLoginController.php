<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthRole;
use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Models\Rider\Rider;
use App\Support\EmailRegistry;
use App\Support\SignedOtpLink;
use App\Support\SignedRegistrationResubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     *   - Seller    → seller dashboard
     *   - Logistics → logistics dashboard
     *   - Buyer     → buyer home
     *
     * Priority: seller guard is tried first because it is the most
     * privileged role. Logistics is tried next, then buyer. If a single
     * email exists in multiple tables with a valid password, the more
     * privileged session is preferred.
     */
    public function login(Request $request)
    {
        $request->session()->flash('auth.open_login_modal', true);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = $credentials['email'];
        $password = $credentials['password'];
        $remember = $request->boolean('remember');

        foreach (AuthRole::publicOrder() as $role) {
            if ($this->tryGuard($role->guard(), $email, $password, $remember, $request)) {
                $request->session()->forget('auth.open_login_modal');

                // After a successful guard attempt Laravel has already stored the
                // user on that guard, so PaymentTreatment is available here.
                // One account per browser: end every other role's session.
                $this->clearOtherGuards($role->guard());

                if (Auth::guard($role->guard())->user()->status === 'pending'
                    || Auth::guard($role->guard())->user()->status === LogisticsProviderStatus::Pending) {
                    $request->session()->forget('url.intended');

                    return redirect()->to($role->dashboardUrl());
                }

                return redirect()->intended($role->dashboardUrl());
            }
        }

        if ($this->tryRider($email, $password, $remember, $request)) {
            $request->session()->forget('auth.open_login_modal');

            if (Auth::guard('rider')->user()->status === Rider::STATUS_PENDING) {
                $request->session()->forget('url.intended');

                return redirect()->route('rider.dashboard');
            }

            return redirect()->intended(route('rider.dashboard'));
        }

        // Neither guard could authenticate with these credentials.
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Rider login: credentials must match AND the account must be operational
     * (approved/active, email-verified, assigned to an approved and active
     * Station Hub, with a linked Courier). Verified pending riders receive a
     * restricted session; the operational middleware shows their review notice.
     */
    private function tryRider(string $email, string $password, bool $remember, Request $request): bool
    {
        if (! Auth::guard('rider')->attempt(['email' => $email, 'password' => $password], $remember)) {
            return false;
        }
        $rider = Auth::guard('rider')->user();
        $pending = $rider && $rider->status === Rider::STATUS_PENDING && $rider->email_verified_at !== null;
        if (! $rider || (! $pending && ! $rider->isOperational())) {
            Auth::guard('rider')->logout();
            $request->session()->forget('password_hash_rider');

            $request->session()->flash(
                'auth.status_message',
                'Your rider account is not active yet. Please contact your logistics company.',
            );

            return false;
        }
        $request->session()->regenerate();
        $this->clearOtherGuards('rider');
        $this->rememberSession('rider');

        return true;
    }

    /**
     * Attempt authentication against one guard.
     *
     * Approved accounts and verified pending sellers/providers may sign in.
     * Approval middleware restricts pending sessions to the review notice.
     * Other non-approved accounts are logged out with a role-specific hint.
     */
    private function tryGuard(string $guard, string $email, string $password, bool $remember, Request $request): bool
    {
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

        $pending = in_array($guard, ['seller', 'logistics'], true) && $status === 'pending' && $user->email_verified_at !== null;
        if ($status !== 'approved' && ! $pending) {
            Auth::guard($guard)->logout();

            $request->session()->flash('auth.status_message', $this->statusMessage($guard, $status, $user->rejection_reason));
            if ($status === 'rejected' && in_array($guard, ['seller', 'logistics'], true) && $user->email_verified_at !== null) {
                $request->session()->flash('auth.resume_url', SignedRegistrationResubmission::url($user));
                $request->session()->flash('auth.resume_label', 'Edit and resubmit application');
            }

            // An unverified account can finish verification without
            // re-registering — hand the login surface a direct link to that
            // role's OTP page.
            if ($status === 'unverified' && ($resumeUrl = $this->resumeUrl($guard, $user)) !== null) {
                $request->session()->flash('auth.resume_url', $resumeUrl);
                $request->session()->flash('auth.resume_label', 'Verify your email now');
            }

            return false;
        }

        // One e-mail = one role. If the same address is registered under more
        // than one role, refuse instead of silently opening the most privileged
        // one — that ambiguity must be resolved by an administrator.
        $roles = EmailRegistry::rolesFor($email);

        if (count($roles) > 1) {
            Auth::guard($guard)->logout();

            throw ValidationException::withMessages([
                'email' => 'This email is linked to more than one account type ('.EmailRegistry::labelList($roles).'). Please contact support.',
            ]);
        }

        // One account per browser: signing in on this guard ends every other
        // role's session in the same browser.
        $this->clearOtherGuards($guard);
        $this->rememberSession($guard);

        $request->session()->regenerate();

        return true;
    }

    /**
     * Direct link an unverified account can follow to resume verification.
     */
    private function resumeUrl(string $guard, Model $user): ?string
    {
        return match ($guard) {
            'buyer' => SignedOtpLink::showUrl('buyer', $user),
            'seller' => SignedOtpLink::showUrl('seller', $user),
            'logistics' => SignedOtpLink::showUrl('logistics', $user),
            default => null,
        };
    }

    /**
     * Role-specific approval-status message.
     */
    private function statusMessage(string $guard, mixed $status, ?string $rejectionReason = null): string
    {
        $status = is_scalar($status) ? (string) $status : '';

        return match ([$guard, $status]) {
            ['seller', 'unverified'] => 'Your email has not been verified yet. Please check your email for the verification code.',
            ['seller', 'pending'] => 'Your seller account is still pending administrator approval.',
            ['seller', 'rejected'] => 'Your seller registration was not approved. Please contact support or submit a new registration.'.(filled($rejectionReason) ? ' Reason: '.$rejectionReason : ''),
            ['seller', 'suspended'] => 'Your seller account has been suspended. Please contact support.',
            ['logistics', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['logistics', 'pending'] => 'Your logistics provider registration is still awaiting administrator approval.',
            ['logistics', 'rejected'] => 'Your logistics provider registration was not approved.'.(filled($rejectionReason) ? ' Reason: '.$rejectionReason : ''),
            ['logistics', 'suspended'] => 'Your logistics account has been suspended. Please contact support.',
            ['buyer', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['buyer', 'pending'] => 'Your buyer registration is still awaiting administrator approval.',
            ['buyer', 'rejected'] => 'Your buyer registration was not approved.',
            ['buyer', 'suspended'] => 'Your buyer account has been suspended. Please contact support.',
            default => 'Your account is not active.',
        };
    }

    /**
     * Log out from every guard and redirect to the home page.
     */
    public function logout(Request $request): RedirectResponse
    {
        // Logout ends EVERY guard, including the admin panel — a browser
        // session must never leave another role signed in.
        $this->clearAllGuards();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
