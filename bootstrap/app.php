<?php

use App\Http\Middleware\Admin\EnsureAdminRole;
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.role' => EnsureAdminRole::class,
            'seller.approved' => EnsureSellerApproved::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('buyer.*') || $request->is('buyer/*')) {
                return route('buyer.login');
            }

            if ($request->routeIs('seller.*') || $request->is('seller/*')) {
                // Unified login entry point — there is no standalone
                // /seller/login screen any more.
                return route('login');
            }

            return route('admin.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
