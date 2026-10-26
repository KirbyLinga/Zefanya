<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\Incidents;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\Shared\Category;
use App\Models\Wishlist;
use App\Policies\BuyerOrderPolicy;
use App\Policies\IncidentsPolicy;
use App\Policies\SellerOrderFleetPolicy;
use App\Policies\SellerOrderPolicy;
use App\View\Composers\RiderNotificationComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Login / auth throttling ─────────────────────────────────────────
        // Throttle logins per e-mail+IP (5/min) with a looser per-IP ceiling
        // (25/min), so one shared office/NAT IP cannot lock every user out.
        // forgot-password and logout get their own, separate limits — logout
        // must never share the login limiter.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login-email:'.$email.'|'.$request->ip()),
                Limit::perMinute(25)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-email', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('password-email:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('password-email-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('logout', fn (Request $request) => Limit::perMinute(30)
            ->by('logout:'.$request->ip()));

        // Named so they do not share one IP bucket the way `throttle:5,1`
        // and `throttle:10,1` do (same signature, different max).
        // Registration: tight per-email+IP limit, looser per-IP ceiling so a
        // campus/office NAT cannot block every applicant on the same network.
        RateLimiter::for('register', function (Request $request) {
            $email = is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(5)->by('register-email:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('register-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('otp-show', fn (Request $request) => Limit::perMinute(30)
            ->by('otp-show:'.$request->ip()));
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)
            ->by('otp-verify:'.$request->ip()));
        RateLimiter::for('otp-resend', fn (Request $request) => Limit::perMinute(5)
            ->by('otp-resend:'.$request->ip()));
        RateLimiter::for('password-update', fn (Request $request) => Limit::perMinute(5)
            ->by('password-update:'.$request->ip()));
        RateLimiter::for('google-auth', fn (Request $request) => Limit::perMinute(10)
            ->by('google-auth:'.$request->ip()));

        // ── Policies for the order flow (Slice 1) ───────────────────────────
        // BuyerOrderPolicy / SellerOrderFleetPolicy don't match Laravel's
        // auto-discovery naming (Order → OrderPolicy), so they are registered
        // explicitly. The fleet policy is exposed as a named ability because
        // a model can only carry ONE policy and SellerOrder's is the owner's.
        Gate::policy(Incidents::class, IncidentsPolicy::class);
        Gate::policy(Order::class, BuyerOrderPolicy::class);
        Gate::policy(SellerOrder::class, SellerOrderPolicy::class);
        Gate::define('remediate-shipment-address', [SellerOrderFleetPolicy::class, 'remediateAddress']);
        Gate::define('advance-shipment', [SellerOrderFleetPolicy::class, 'advance']);

        // In production every generated URL (routes, assets, pagination, password
        // resets) must be https — proxy headers alone are not guaranteed when the
        // app sits behind a load balancer that terminates TLS.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Login-side single-account enforcement is handled by a Login event
        // listener (see EventServiceProvider). That keeps every login path —
        // password, Google and any future path — in one place instead of in
        // each controller.

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
        View::composer('*', function (ViewInstance $view): void {
            $view->with('wishlistIds', $this->buyerWishlistIds());
        });

        View::composer('Components.navbar', function (ViewInstance $view): void {
            $view->with('cartCount', $this->buyerCartCount());
            $view->with('wishlistCount', $this->buyerWishlistCount());
            $view->with('unreadNotificationCount', $this->buyerUnreadNotificationCount());
            $view->with('categories', $this->getCategories());
        });

        // Rider panel: the bell lives in Layouts/rider so it is present on
        // every page, not only the dashboard.
        View::composer('Layouts.rider', RiderNotificationComposer::class);
    }

    /**
     * Number of the authenticated buyer's cart lines (available or
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
            ->count();
    }

    /**
     * Total count of the authenticated buyer's wishlist items.
     * 0 for guests.
     */
    private function buyerWishlistCount(): int
    {
        return $this->buyerWishlistIds()->count();
    }

    private function buyerWishlistIds(): Collection
    {
        $request = request();
        $key = 'buyer_wishlist_ids.'.(Auth::guard('buyer')->id() ?? 'guest');

        if (! $request->attributes->has($key)) {
            $ids = Auth::guard('buyer')->check()
                ? Wishlist::query()
                    ->where('buyer_id', Auth::guard('buyer')->id())
                    ->whereHas('product')
                    ->pluck('product_id')
                    ->mapWithKeys(fn ($id) => [(int) $id => true])
                : collect();

            $request->attributes->set($key, $ids);
        }

        return $request->attributes->get($key);
    }

    /**
     * Count of unread database notifications for the authenticated buyer.
     * 0 for guests.
     */
    private function buyerUnreadNotificationCount(): int
    {
        if (! Auth::guard('buyer')->check()) {
            return 0;
        }

        return (int) Auth::guard('buyer')->user()
            ->unreadNotifications()
            ->count();
    }

    /**
     * Get all categories for the navbar dropdown.
     * Returns collection of categories ordered by ID.
     */
    private function getCategories()
    {
        return Category::query()
            ->orderBy('id')
            ->get(['id', 'label', 'icon']);
    }
}
