<?php

use App\Enums\AuthRole;
use App\Http\Middleware\Admin\EnsureAdminRole;
use App\Http\Middleware\AuthenticateRoleSession;
use App\Http\Middleware\EnforceSingleBrowserAccount;
use App\Http\Middleware\Logistics\EnsureLogisticsApproved;
use App\Http\Middleware\RedirectIfAuthenticatedRole;
use App\Http\Middleware\Rider\EnsureRiderActive;
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
            'guest.role' => RedirectIfAuthenticatedRole::class,
            'logistics.approved' => EnsureLogisticsApproved::class,
            'rider.active' => EnsureRiderActive::class,
            'seller.approved' => EnsureSellerApproved::class,
        ]);

        // Single-account-per-browser net + password-change session guard, on
        // every web request (buyer/seller/logistics/admin routes all use it).
        $middleware->web(append: [
            EnforceSingleBrowserAccount::class,
            AuthenticateRoleSession::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin', 'admin/*')) {
                return route('admin.login');
            }

            return route('home', ['login' => 1]);
        });

        $middleware->redirectUsersTo(
            fn (): string => AuthRole::current()?->dashboardUrl() ?? '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
