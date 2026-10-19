<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Password reset for EVERY role (buyer, seller, logistics, admin).
 *
 * Zefanya has four independent login tables and guards, so Laravel's default
 * single-broker reset flow does not fit. Each role has its own password broker
 * (config/auth.php → passwords.{buyers,sellers,logistics,admins}) AND its own
 * token table, so the same address in two role tables can never overwrite the
 * other role's token. This controller resolves the right broker from the
 * submitted email by simply trying each one — a broker whose provider has no
 * such user returns INVALID_USER and is skipped.
 *
 * The request response never reveals whether an email exists
 * (account-enumeration safe): a miss returns the same "link sent" message as a
 * hit. A successful reset rotates the account's remember_token, and
 * AuthenticateRoleSession ends every OTHER session still holding the old
 * password.
 */
class PasswordResetController extends Controller
{
    /**
     * Brokers in resolution priority — most privileged first, matching
     * UnifiedLoginController. Each maps to a provider in config/auth.php.
     *
     * @var list<string>
     */
    private const BROKERS = ['admins', 'sellers', 'logistics', 'buyers'];

    /**
     * GET /forgot-password — the request form.
     */
    public function showRequestForm(): View
    {
        return view('Auth.forgot-password');
    }

    /**
     * POST /forgot-password — email a reset link to whichever role owns the
     * address.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        foreach (self::BROKERS as $broker) {
            Password::broker($broker)->sendResetLink($request->only('email'));
        }

        // ALWAYS the same response, hit or miss, so the form cannot be used to
        // discover which addresses have an account.
        return back()->with('status', __(Password::RESET_LINK_SENT));
    }

    /**
     * GET /reset-password/{token} — the new-password form.
     */
    public function showResetForm(Request $request, string $token): View
    {
        return view('Auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * POST /reset-password — validate the token and store the new password.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');

        foreach (self::BROKERS as $broker) {
            $status = Password::broker($broker)->reset(
                $credentials,
                function ($user, string $password): void {
                    // The `password` cast on every role model hashes the plain
                    // value on set, so pass the raw password through.
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();
                },
            );

            if ($status === Password::PASSWORD_RESET) {
                return redirect()->route('home', ['login' => 1])
                    ->with('auth.status_message', __($status));
            }

            // A broker that owns the email but rejected the token is the
            // authoritative answer — stop and show the error.
            if ($status === Password::INVALID_TOKEN) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => __($status)]);
            }
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __(Password::INVALID_USER)]);
    }
}
