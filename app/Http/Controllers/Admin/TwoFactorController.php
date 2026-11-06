<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Models\Admin\Admin;
use App\Services\Admin\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * TOTP two-factor authentication for ADMIN accounts only.
 *
 * Password login is completed first; when the account has enrolled, the guard
 * is logged out again and a short-lived session marker holds the pending admin
 * id until a valid authenticator (or recovery) code is supplied. Buyer, seller,
 * logistics and rider logins never touch this controller.
 */
class TwoFactorController extends Controller
{
    use ClearsOtherGuards;

    public const PENDING_ID = 'admin.2fa.id';

    public const PENDING_REMEMBER = 'admin.2fa.remember';

    public const PENDING_SECRET = 'admin.2fa.pending_secret';

    public const RECOVERY_CODES_FLASH = 'admin.2fa.recovery_codes';

    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    /** Enrolment / status screen for a signed-in admin. */
    public function show(Request $request): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $secret = null;
        $uri = null;
        $qrSvg = null;

        if (! $admin->hasEnabledTwoFactor()) {
            $pending = $request->session()->get(self::PENDING_SECRET);

            if (! is_string($pending) || $pending === '') {
                $pending = $this->totp->generateSecret();
                $request->session()->put(self::PENDING_SECRET, $pending);
            }

            $secret = $pending;
            $uri = $this->totp->provisioningUri(
                $secret,
                (string) $admin->email,
                (string) config('admin.two_factor_issuer', 'Zefanya Admin'),
            );
            $qrSvg = $this->totp->qrSvg($uri);
        }

        return view('Admin.Security.two-factor', [
            'admin' => $admin,
            'secret' => $secret,
            'uri' => $uri,
            'qrSvg' => $qrSvg,
            'recoveryCodes' => (array) $request->session()->get(self::RECOVERY_CODES_FLASH, []),
        ]);
    }

    /** Confirm enrolment with a code from the authenticator app. */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        if ($admin->hasEnabledTwoFactor()) {
            return back()->withErrors(['code' => 'Two-factor authentication is already enabled.']);
        }

        $secret = (string) $request->session()->get(self::PENDING_SECRET, '');

        if ($secret === '' || ! $this->totp->verify($secret, (string) $request->input('code'))) {
            return back()->withErrors([
                'code' => 'That code is invalid or expired. Check your authenticator app and try again.',
            ]);
        }

        $recovery = $this->totp->generateRecoveryCodes();

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery['hashed'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget(self::PENDING_SECRET);
        $request->session()->flash(self::RECOVERY_CODES_FLASH, $recovery['plain']);

        return redirect()
            ->route('admin.two-factor.show')
            ->with('status', 'Two-factor authentication is now enabled.');
    }

    /** Disable 2FA after re-proving the password and a current/recovery code. */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        if (! Hash::check((string) $request->input('password'), (string) $admin->password)) {
            return back()->withErrors(['password' => 'Your password is incorrect.']);
        }

        if (! $admin->hasEnabledTwoFactor()) {
            return back()->withErrors(['code' => 'Two-factor authentication is not enabled.']);
        }

        $code = (string) $request->input('code');

        if (
            ! $this->totp->verify((string) $admin->two_factor_secret, $code)
            && ! $this->consumeRecoveryCode($admin, $code)
        ) {
            return back()->withErrors(['code' => 'That authentication code is invalid.']);
        }

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $request->session()->forget(self::PENDING_SECRET);

        return back()->with('status', 'Two-factor authentication has been disabled.');
    }

    /** Show the second-factor challenge for a pending password login. */
    public function challenge(Request $request): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $request->session()->has(self::PENDING_ID)) {
            return redirect()->route('admin.login');
        }

        return view('Admin.Auth.two-factor-challenge');
    }

    /** Verify the second factor and only then open the admin session. */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $adminId = (int) $request->session()->get(self::PENDING_ID, 0);
        $admin = $adminId > 0 ? Admin::query()->find($adminId) : null;

        if (! $admin instanceof Admin || ! $admin->hasEnabledTwoFactor()) {
            $request->session()->forget([self::PENDING_ID, self::PENDING_REMEMBER]);

            return redirect()
                ->route('admin.login')
                ->withErrors(['code' => 'Your sign-in session expired. Please log in again.']);
        }

        $code = (string) $request->input('code');

        if (
            ! $this->totp->verify((string) $admin->two_factor_secret, $code)
            && ! $this->consumeRecoveryCode($admin, $code)
        ) {
            return back()->withErrors([
                'code' => 'That authentication code is invalid or expired.',
            ]);
        }

        $remember = (bool) $request->session()->get(self::PENDING_REMEMBER, false);

        $request->session()->forget([self::PENDING_ID, self::PENDING_REMEMBER]);

        Auth::guard('admin')->login($admin, $remember);
        $this->clearOtherGuards('admin');
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    private function consumeRecoveryCode(Admin $admin, string $code): bool
    {
        $hashes = $admin->twoFactorRecoveryCodeHashes();
        $index = $this->totp->matchRecoveryCode($code, $hashes);

        if ($index === null) {
            return false;
        }

        unset($hashes[$index]);

        $admin->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return true;
    }
}
