<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\Shared\Category;
use App\Models\Wishlist;
use App\Policies\BuyerOrderPolicy;
use App\Policies\SellerOrderFleetPolicy;
use App\Policies\SellerOrderPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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
        RateLimiter::for('register', function (Request $request) {
            return [Limit::perMinute(5)->by('rider-register:'.strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()), Limit::perMinute(30)->by('rider-register-ip:'.$request->ip())];
        });
        foreach (['otp-show' => 30, 'otp-verify' => 10, 'otp-resend' => 10, 'rider-decisions' => 20] as $name => $max) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($max)->by($name.':'.$request->ip()));
        }
        View::composer('Components.rider-register-modal', function (ViewInstance $view): void {
            $view->with('riderProviders', LogisticsProvider::where('status', 'approved')->orderBy('business_name')->orderBy('id')->get(['id', 'business_name']));
        });

        // ── Policies for the order flow (Slice 1) ───────────────────────────
        // BuyerOrderPolicy / SellerOrderFleetPolicy don't match Laravel's
        // auto-discovery naming (Order → OrderPolicy), so they are registered
        // explicitly. The fleet policy is exposed as a named ability because
        // a model can only carry ONE policy and SellerOrder's is the owner's.
        Gate::policy(Order::class, BuyerOrderPolicy::class);
        Gate::policy(SellerOrder::class, SellerOrderPolicy::class);
        Gate::define('advance-shipment', [SellerOrderFleetPolicy::class, 'advance']);

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
            $view->with('wishlistCount', $this->buyerWishlistCount());
            $view->with('unreadNotificationCount', $this->buyerUnreadNotificationCount());
            $view->with('categories', $this->getCategories());
        });
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

    /**
     * Total count of the authenticated buyer's wishlist items.
     * 0 for guests.
     */
    private function buyerWishlistCount(): int
    {
        if (! Auth::guard('buyer')->check()) {
            return 0;
        }

        return (int) Wishlist::query()
            ->where('buyer_id', Auth::guard('buyer')->id())
            ->count();
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
            ->get(['id', 'label']);
    }
}
