<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Services\Auth\GoogleApiTokenVerifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdTokenVerifier::class, GoogleApiTokenVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A guarded attribute passed to create()/update() must fail loudly outside
        // production instead of being dropped. In production the default remains
        // fail-open for availability, but every caller below is exercised by the
        // test suite with this guard on.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        $this->configureRateLimiting();

        // In production every generated URL (routes, assets, pagination, password
        // resets) must be https — proxy headers alone are not guaranteed when the
        // app sits behind a load balancer that terminates TLS.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // buyer.php already applies its own prefix('buyer') and name('buyer.')
        // inside the file (line 9), so we only wrap it in the web middleware here.
        // admin.php and seller.php are loaded via bootstrap/app.php's then: closure.
        Route::middleware('web')
            ->group(base_path('routes/buyer.php'));

        // The physical components folder is "Components" (capital C) while Laravel's
        // default anonymous-component root is "components". On case-insensitive
        // filesystems (Windows dev) <x-seller.*> resolves either way, but on
        // case-sensitive filesystems (Linux deploy) it would not. Register the
        // capitalized folder as an additional anonymous component path so module
        // components (e.g. x-seller.sidebar) resolve on every platform.
        // Must be an absolute path — it is registered as a view namespace.
        Blade::anonymousComponentPath(
            resource_path('views/Components')
        );

        // The navbar cart badge (#cartCount) must show the real cart size on
        // EVERY buyer page. A view composer is used instead of passing the
        // count from each layout so every existing
        // @include('Components.navbar') call stays correct untouched.
        // A composer's with() runs at render time, so it wins over the
        // include's own data. Guests short-circuit before any query runs.
        View::composer('Components.navbar', function (ViewInstance $view): void {
            $view->with('cartCount', $this->buyerCartCount());
        });
    }

    /**
     * Named buckets for the mobile API.
     *
     * Each bucket is keyed on the actor the request is about, never on the URL
     * alone. A second route into the same action therefore cannot buy a second
     * allowance: the legacy `/api/...` and `/api/v1/...` login routes share
     * `api-login`, and so do the buyer, courier and logistics spellings of it.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-login', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email', '')));

            // Lower-casing is part of the key on purpose: an attacker who can
            // change the case of the mailbox must not get a fresh counter.
            return Limit::perMinute(5)
                ->by(($email !== '' ? $email : 'no-email').'|'.$request->ip());
        });

        RateLimiter::for('api-register', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('api-google', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('ip:'.$request->ip()));

        // The delivery handover is the expensive, state-changing milestone: ten
        // attempts a minute per courier, on top of the per-order wrong-code
        // lockout inside OrderDeliveryService.
        RateLimiter::for('api-delivery-verify', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->apiActorKey($request, 'courier')));

        // The only password-mutating route today is POST /api/account/password.
        // (There is no password-reset route on either surface yet; when one
        // lands it should reuse this 3/min bucket.)
        RateLimiter::for('api-account-password', fn (Request $request): Limit => Limit::perMinute(3)
            ->by($this->apiActorKey($request)));

        // Registration documents, review photos and delivery proof all land
        // here. Twenty a minute per account is far above a person retrying a
        // camera, and stops a loop from filling the private disk.
        RateLimiter::for('api-uploads', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($this->apiActorKey($request)));

        // The floor for every route in the api group. The stricter buckets
        // above still apply; this one is inherited by any endpoint that does
        // not need a special rule, so a new route cannot arrive unlimited.
        RateLimiter::for('api-default', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->apiActorKey($request)));
    }

    /**
     * A cache key for "this account" or, before authentication, "this address".
     *
     * Buyer and LogisticsProvider ids are separate sequences, so the model
     * class is part of the key: buyer 7 and rider 7 are different actors.
     */
    private function apiActorKey(Request $request, string $namespace = 'actor'): string
    {
        $user = $request->user();

        if ($user === null) {
            return 'ip:'.$request->ip();
        }

        return $namespace.':'.$user::class.':'.$user->getAuthIdentifier();
    }

    /**
     * Total quantity of the authenticated buyer's cart lines (available or
     * not) — the number shown in the navbar badge. 0 for guests.
     *
     * One indexed aggregate per page render; deliberately not eager-loading
     * anything here.
     */
    private function buyerCartCount(): int
    {
        if (! Auth::guard('buyer')->check()) {
            return 0;
        }

        return (int) CartItem::query()
            ->where('buyer_id', Auth::guard('buyer')->id())
            ->sum('quantity');
    }
}
