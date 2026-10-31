<?php

use App\Enums\AuthRole;
use App\Http\Middleware\Admin\EnsureAdminRole;
use App\Http\Middleware\Admin\EnsureAdminTwoFactor;
use App\Http\Middleware\ApiRole;
use App\Http\Middleware\AuthenticateRoleSession;
use App\Http\Middleware\Buyer\EnsureBuyerActive;
use App\Http\Middleware\EnforceSingleBrowserAccount;
use App\Http\Middleware\Logistics\EnsureLogisticsApproved;
use App\Http\Middleware\RedirectIfAuthenticatedRole;
use App\Http\Middleware\Rider\EnsureRiderActive;
use App\Http\Middleware\Seller\EnsureSellerApproved;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
            Route::middleware('web')->group(base_path('routes/seller.php'));
            Route::middleware('web')->group(base_path('routes/logistics.php'));
            Route::middleware('web')->group(base_path('routes/rider.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted reverse proxies are configured in config/trustedproxy.php via
        // the TRUSTED_PROXIES environment variable. Laravel's TrustProxies
        // middleware reads that config at request time, so no wildcard is set
        // here: by default NO proxy is trusted and X-Forwarded-* headers from
        // direct clients are ignored (client IPs cannot be spoofed).
        //
        // TODO (deployment): set TRUSTED_PROXIES to the exact address/CIDR of the
        // Cloudflare Tunnel connector (or load balancer) that forwards requests
        // to this app. That value cannot be guessed from this repository.

        $middleware->alias([
            'admin.role' => EnsureAdminRole::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
            'guest.role' => RedirectIfAuthenticatedRole::class,
            'logistics.approved' => EnsureLogisticsApproved::class,
            'rider.active' => EnsureRiderActive::class,
            'seller.approved' => EnsureSellerApproved::class,
            'buyer.active' => EnsureBuyerActive::class,
            'api.role' => ApiRole::class,
        ]);

        // Single-account-per-browser net + password-change session guard, on
        // every web request (buyer/seller/logistics/admin routes all use it).
        $middleware->web(append: [
            EnforceSingleBrowserAccount::class,
            AuthenticateRoleSession::class,
        ]);

        // Revoke stale credentials before route authentication can authorize a request.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AuthenticateRoleSession::class);
        $middleware->prependToPriorityList(AuthenticateRoleSession::class, EnforceSingleBrowserAccount::class);

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
        // On API requests render authentication failures as a consistent JSON
        // body (success, message, data, errors) instead of the framework's
        // default HTML "Unauthenticated." page or string.
        $exceptions->renderable(function (AuthenticationException $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $e->getMessage() ?? 'Unauthenticated.';

            $guards = $e->guards();

            $errors = $guards === ['sanctum'] || empty($guards)
                ? ['token' => ['The provided token is invalid, expired, or belongs to a different role.']]
                : ['token' => ['Unauthenticated. This API requires a bearer token with the expected role.']];

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
                'errors' => $errors,
            ], 401);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
