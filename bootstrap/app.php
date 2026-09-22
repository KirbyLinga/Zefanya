<?php

use App\Http\Middleware\Admin\EnsureAdminRole;
use App\Http\Middleware\Logistics\EnsureLogisticsApproved;
use App\Http\Middleware\Seller\EnsureSellerApproved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
            Route::middleware('web')->group(base_path('routes/seller.php'));
            Route::middleware('web')->group(base_path('routes/logistics.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy / Cloudflare / load balancer, trust every proxy's
        // X-Forwarded-* headers so Laravel generates https:// URLs and marks the
        // session cookie secure in production. Kept separate from the aliases below.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'admin.role' => EnsureAdminRole::class,
            'logistics.approved' => EnsureLogisticsApproved::class,
            'seller.approved' => EnsureSellerApproved::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            $path = $request->path();

            if (str_starts_with($path, 'logistics/')) {
                return route('login');
            }

            if (str_starts_with($path, 'seller/')) {
                return route('seller.login');
            }

            if (str_starts_with($path, 'admin/')) {
                return route('admin.login');
            }

            if (str_starts_with($path, 'buyer/')) {
                return route('buyer.login');
            }

            return route('login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            $path = $request->path();

            if (str_starts_with($path, 'logistics/')) {
                return route('logistics.dashboard');
            }

            if (str_starts_with($path, 'seller/')) {
                return route('seller.dashboard');
            }

            if (str_starts_with($path, 'admin/')) {
                return route('admin.dashboard');
            }

            if (str_starts_with($path, 'buyer/')) {
                return route('buyer.home');
            }

            return route('home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
