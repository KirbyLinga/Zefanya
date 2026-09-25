<?php

namespace App\Providers;

use App\Models\CartItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
