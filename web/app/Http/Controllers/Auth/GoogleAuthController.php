<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthRole;
use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use App\Support\SignedOtpLink;
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
 * never created here — buyer/seller/logistics registration keeps its existing,
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
     * Google loginable roles (string values of AuthRole::publicOrder()).
     * Never includes admin — admins use /admin/login.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        return AuthRole::publicGuards();
    }

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
        // dismissed or denied — treat that as a cancelled attempt.
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

        // Ignore admins here: the same email may exist in `admins` for
        // /admin/login, but Google must only consider AuthRole::publicOrder().
        $roles = array_values(array_intersect(EmailRegistry::rolesFor($email), self::roles()));

        if (count($roles) > 1) {
            return $this->failed(
                'This email is linked to more than one account type ('.EmailRegistry::labelList($roles).'). Please contact support.'
            );
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
            $status = match (true) {
                is_string($account->status) => $account->status,
                $account->status instanceof \BackedEnum => $account->status->value,
                default => '',
            };

            $extra = [];

            if ($status === 'unverified') {
                $extra['auth.resume_url'] = SignedOtpLink::showUrl($role, $account);
                $extra['auth.resume_label'] = 'Verify your email now';
            }

            return $this->failed($statusMessage, $extra);
        }

        Auth::guard(self::ROLE_MAP[$role]['guard'])->login($account);

        // One account per browser: end every other role's session and stash the
        // suppressed session so a later same-guard login (password reset, etc.)
        // can restore it.
        $this->clearOtherGuards(self::ROLE_MAP[$role]['guard']);

        return redirect()->route(AuthRole::from($role)->dashboardRoute());
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
            if ($account->newQuery()->where('google_id', $googleId)->exists()) {
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
        foreach (AuthRole::publicOrder() as $authRole) {
            $role = $authRole->value;
            $model = self::ROLE_MAP[$role]['model'];

            if ($model::query()->where('email', $email)->exists()) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Approval gate — the same rule and wording password sign-in applies in
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
            ['seller', 'unverified'] => 'Your email has not been verified yet. Please check your email for the verification code.',
            ['seller', 'pending'] => 'Your seller account is still pending administrator approval.',
            ['seller', 'rejected'] => 'Your seller registration was not approved. Please contact support or submit a new registration.',
            ['logistics', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['logistics', 'pending'] => 'Your logistics provider registration is still awaiting administrator approval.',
            ['logistics', 'rejected'] => 'Your logistics provider registration was not approved.',
            ['buyer', 'unverified'] => 'Please verify your email before logging in. Check your inbox for the code.',
            ['buyer', 'pending'] => 'Your buyer registration is still awaiting administrator approval.',
            ['buyer', 'rejected'] => 'Your buyer registration was not approved.',
            default => 'Your account is not active.',
        };
    }

    /**
     * Back to the login screen with the reason whenever Google sign-in cannot
     * proceed (unknown email, link conflict, or the approval gate). Matches the
     * class docblock and the redirect the approval middleware uses.
     *
     * @param  array<string, mixed>  $extra
     */
    private function failed(string $message, array $extra = []): RedirectResponse
    {
        $redirect = redirect()->route('home', ['login' => 1])->with('auth.status_message', $message);

        foreach ($extra as $key => $value) {
            $redirect->with($key, $value);
        }

        return $redirect;
    }

    /**
     * Log out from every guard and redirect to the home page.
     */
    public function logout(Request $request): RedirectResponse
    {
        $this->clearAllGuards();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
