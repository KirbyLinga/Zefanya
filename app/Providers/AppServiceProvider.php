<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(
            resource_path('views/Components')
        );
    }
}
