<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Google Sign-In for the three loginable roles (buyer, seller, logistics).
 *
 * This is a LINK-only flow: the verified Google email is matched against the
 * role table and the Google account id is attached to that row. Accounts are
 * never created here â€” buyer/seller/logistics registration keeps its existing,
 * approval-gated flow, so an unknown email is sent back to the login screen.
 *
 * The role-agnostic entry points power the single "Continue with Google"
 * button in the shared login modal. The role-scoped pair exists for any
 * surface that already knows which panel is being signed in to.
 */
class GoogleAuthController extends Controller
{
    use ClearsOtherGuards;

    /** Shown when the Google email matches no row in any role table. */
    public const NO_ACCOUNT_MESSAGE = 'No account found for this Google email. Please register first.';

    /**
     * Resolution order for the role-agnostic button, most privileged role
     * first â€” the same order UnifiedLoginController uses, so one email that
     * exists in several role tables always lands on the same panel no matter
     * which sign-in method was used.
     *
     * @var list<string>
     */
    public const ROLES = ['seller', 'logistics', 'buyer'];

    /**
     * Session guard, model and landing route per role.
     *
     * @var array<string, array{guard: string, model: class-string<Model>, home: string}>
     */
    private const ROLE_MAP = [
        'buyer' => ['guard' => 'buyer', 'model' => Buyer::class, 'home' => 'buyer.home'],
        'seller' => ['guard' => 'seller', 'model' => Seller::class, 'home' => 'seller.dashboard'],
        'logistics' => ['guard' => 'logistics', 'model' => LogisticsProvider::class, 'home' => 'logistics.dashboard'],
    ];

    /**
     * Role-agnostic entry point used by the shared login modal. The role is
     * resolved from the Google email in callback().
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        return $this->handleCallback($request, null);
    }

    /**
     * Role-scoped redirect (e.g. /auth/google/seller/redirect). The `{role}`
     * route parameter is not needed to build the redirect: the callback it
     * returns to carries the role, and Socialite keeps its state in the session.
     */
    public function redirectForRole(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callbackForRole(Request $request, string $role): RedirectResponse
    {
        return $this->handleCallback($request, $role);
    }

    /**
     * Resolve the account from the verified Google email, link it, apply the
     * approval gate and open the role's session.
     */
    private function handleCallback(Request $request, ?string $role): RedirectResponse
    {
        // Google returns ?error=access_denied when the consent screen is
        // dismissed or denied â€” treat that as a cancelled attempt.
        if ($request->filled('error')) {
            return $this->failed('Google sign-in was cancelled. Please try again.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failed('Google sign-in expired. Please try again.');
        }

        $email = $googleUser->getEmail();

        if (! is_string($email) || $email === '') {
            return $this->failed('Google did not share an email address. Please sign in with your password.');
        }

        $role ??= $this->resolveRole($email);

        if ($role === null || ! isset(self::ROLE_MAP[$role])) {
            return $this->failed(self::NO_ACCOUNT_MESSAGE);
        }

        $account = self::ROLE_MAP[$role]['model']::query()->where('email', $email)->first();

        if (! $account instanceof Model) {
            return $this->failed(self::NO_ACCOUNT_MESSAGE);
        }

        return $this->linkAndLogin($request, $role, $account, (string) $googleUser->getId());
    }

    /**
     * Link on first sign-in, then enforce the approval gate password sign-in
     * also enforces, then open the session.
     */
    private function linkAndLogin(Request $request, string $role, Model $account, string $googleId): RedirectResponse
    {
        $roles = EmailRegistry::rolesFor($account->email);
        if (count($roles) > 1 && in_array('rider', $roles, true)) {
            return $this->failed('This email belongs to more than one account type. Please contact support.');
        }
        $googleId = trim($googleId);

        if ($googleId === '') {
            return $this->failed('Google did not share an account id. Please try again.');
        }

        $linkError = $this->linkGoogleAccount($account, $googleId);

        if ($linkError !== null) {
            return $this->failed($linkError);
        }

        $statusMessage = $this->statusMessage($role, $account);

        if ($statusMessage !== null) {
            return $this->failed($statusMessage);
        }

        Auth::guard(self::ROLE_MAP[$role]['guard'])->login($account);
        $this->clearOtherGuards(self::ROLE_MAP[$role]['guard']);
        $request->session()->regenerate();

        return redirect()->intended(route(self::ROLE_MAP[$role]['home']));
    }

    /**
     * Attach google_id when the row has none yet. Returns null when linked (or
     * already linked to this same Google account), otherwise the message to
     * show. The id always comes from the verified Google payload, never from
     * request data (project rule #9).
     */
    private function linkGoogleAccount(Model $account, string $googleId): ?string
    {
        if ($account->google_id === null) {
            $alreadyLinked = $account->newQuery()->where('google_id', $googleId)->exists();

            if ($alreadyLinked) {
                return 'That Google account is already linked to another Zefanya account.';
            }

            $account->forceFill(['google_id' => $googleId])->save();

            return null;
        }

        if ((string) $account->google_id !== $googleId) {
            return 'This email is linked to a different Google account. Please sign in with your password.';
        }

        return null;
    }

    /**
     * Which role table owns this Google email, most privileged role first.
     */
    private function resolveRole(string $email): ?string
    {
        foreach (self::ROLES as $role) {
            $model = self::ROLE_MAP[$role]['model'];

            if ($model::query()->where('email', $email)->exists()) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Approval gate â€” the same rule and wording password sign-in applies in
     * UnifiedLoginController::statusMessage(). Null means the account may log in.
     */
    private function statusMessage(string $role, Model $account): ?string
    {
        $status = match (true) {
            is_string($account->status) => $account->status,
            $account->status instanceof \BackedEnum => $account->status->value,
            default => '',
        };

        if ($status === 'approved') {
            return null;
        }

        return match ([$role, $status]) {
            ['seller', 'suspended'] => 'Your account has been suspended. Please contact support.',
            ['seller', 'unverified'] => 'Your email has not been verified yet. Please check your email for the verification code.',
            ['seller', 'pending'] => 'Your seller account is still pending administrator approval.',
            ['seller', 'rejected'] => 'Your seller registration was not approved. Please contact support or submit a new registration.',
            ['logistics', 'suspended'] => 'Your account has been suspended. Please contact support.',
            ['logistics', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['logistics', 'pending'] => 'Your logistics provider registration is still awaiting administrator approval.',
            ['logistics', 'rejected'] => 'Your logistics provider registration was not approved.',
            ['buyer', 'suspended'] => 'Your account has been suspended. Please contact support.',
            ['buyer', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['buyer', 'pending'] => 'Your buyer registration is still awaiting administrator approval.',
            ['buyer', 'rejected'] => 'Your buyer registration was not approved.',
            default => 'Your account is not active.',
        };
    }

    /**
     * Back to the register page with the reason when no account is found.
     * For other errors, redirect to register page with message.
     */
    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('register.type')->with('auth.status_message', $message);
    }
}
