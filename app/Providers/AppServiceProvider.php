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
        Route::middleware('web')
            ->prefix('admin')
            ->name('admin.')
            ->group(base_path('routes/admin.php'));

        // buyer.php already applies its own prefix('buyer') and name('buyer.')
        // inside the file (line 9), so we only wrap it in the web middleware here.
        Route::middleware('web')
            ->group(base_path('routes/buyer.php'));
    }
}
