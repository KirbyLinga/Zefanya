# Zefanya Laravel backend - API surface

The server behind the Flutter app. Two doors into one database: the API (`/api/*`) and the web app.

## Wiring

### web/bootstrap\app.php  (92 lines)

```php
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
        // The mobile app's surface. Registered here rather than added to every
        // mobile-only endpoint's middleware, because the difference that matters
        // is the whole group: `api` has no session and no CSRF token, which is
        // exactly what a phone can offer. Everything under this prefix is
        // token-authenticated or public-on-purpose; nothing shares a cookie with
        // the web app.
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
```

### web/config\filesystems.php  (80 lines)

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
```

### NOT FOUND: web/config\cors.php

### NOT FOUND: web/config\sanctum.php

### web/routes\api.php  (119 lines)

```php
<?php

// routes/api.php
//
// The mobile app's surface — see bootstrap/app.php for why it is a separate
// group. Everything here returns JSON and nothing here reads a session cookie.
//
// ## Why no `auth:sanctum` on the registration half
//
// A person signing up has no token yet, by definition. The OTP routes are
// therefore public and keyed on the server's own row id, exactly as
// `routes/web.php` has them (the web modal posts to the same shape of URL). The
// row id is not a secret: it is an address for a row whose only usable secret is
// the 6-digit code, which is hashed in the database and rate-limited here.
//
// ## The throttles are copies on purpose — and each one is its own bucket
//
// They mirror `routes/web.php`'s per-route limits rather than relying on the
// `api` group having any. A second surface must not be a way around the first
// one's protection, and the limits that matter (5 registrations/min, 10 code
// attempts/min, 3 resends/5min) are the same numbers a person's phone hits.
//
// The third parameter is the part that is *not* a copy, and it fixes a bug the
// web routes still have. `ThrottleRequests::resolveRequestSignature()` keys a
// guest by `domain|ip` alone — no route in the key — so N comma-separated
// routes without a prefix share one counter per IP, and the limit that applies
// is whichever route you happen to hit. Found by running it: the very first
// resend answered `429 Too Many Attempts`, because four *other* requests from
// the same address had been counted against the same bucket. A prefix gives
// each route its own counter, verified against this app's real traffic.

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Buyer\RegisterBuyerController;
use App\Http\Controllers\Api\Buyer\VerifyBuyerOtpController;
use App\Http\Controllers\Api\Cart\VoucherController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Order\OrderController;
use Illuminate\Support\Facades\Route;

// ── Registration (public: there is no session to require yet) ───────────────

Route::post('/register/buyer', [RegisterBuyerController::class, 'store'])
    ->name('api.register.buyer.store')
    ->middleware('throttle:5,1,api-register');

Route::post('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'verify'])
    ->name('api.register.buyer.verify-otp')
    ->middleware('throttle:10,1,api-otp-verify');

Route::post('/register/buyer/verify-otp/{buyer}/resend', [VerifyBuyerOtpController::class, 'resend'])
    ->name('api.register.buyer.verify-otp.resend')
    ->middleware('throttle:3,5,api-otp-resend');

// ── Signing in (public: this is where a token comes from) ───────────────────

// One path per role, because one guard is one provider: buyers and logistics
// providers are different tables with different credential rules, and a single
// endpoint with a `role` field would have to decide which one it means *after*
// accepting a password. Courier sign-in is deliberately absent rather than
// stubbed — `logistics_providers` has no API surface yet, and a route that
// always answers "not built yet" is worse than a route that isn't there.
Route::post('/login/buyer', [LoginController::class, 'store'])
    ->name('api.login.buyer')
    ->middleware('throttle:5,1,api-login');

// ── The catalogue (public: browsing needs no account) ───────────────────────
//
// Four read-only routes, one bucket each. They are separate buckets for the same
// reason the auth routes are: `ThrottleRequests` keys a guest by `domain|ip` with
// no route in the key, so without a prefix all four would share one counter and
// the limit that applied would be whichever route the app happened to hit first.
//
// 60 reads a minute is deliberately far above what a phone does while someone
// scrolls — it is there to stop a loop, not to meter browsing.
Route::get('/products', [ProductController::class, 'index'])
    ->name('api.products.index')
    ->middleware('throttle:60,1,api-products-index');

Route::get('/products/{product}', [ProductController::class, 'show'])
    ->name('api.products.show')
    ->middleware('throttle:60,1,api-products-show');

Route::get('/categories', [CategoryController::class, 'index'])
    ->name('api.categories.index')
    ->middleware('throttle:60,1,api-categories');

Route::get('/vouchers', [VoucherController::class, 'index'])
    ->name('api.vouchers.index')
    ->middleware('throttle:60,1,api-vouchers');

// ── Authenticated ───────────────────────────────────────────────────────────

// Exists so the token has something to prove itself against: everything else
// here either precedes a token or is public, which would leave "is this token
// real?" unanswerable from outside.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [LoginController::class, 'show'])
        ->name('api.me');

    // The buyer's own parcels, newest checkout first. One throttle bucket, like the
    // catalogue's reads but lower: a history is opened, not scrolled for minutes,
    // and a loop on this route is a bug in the app rather than a busy person.
    Route::get('/orders', [OrderController::class, 'index'])
        ->name('api.orders.index')
        ->middleware('throttle:60,1,api-orders-index');

    // Placing one. Its own bucket, and a tighter one than the reads: sixty a
    // minute is a scroll, ten is a person — and a loop here mints orders rather
    // than pages. It is also a bucket of its own so that a failed placement
    // cannot consume the history route's allowance, which is what a single
    // shared counter per IP would do.
    Route::post('/orders', [OrderController::class, 'store'])
        ->name('api.orders.store')
        ->middleware('throttle:10,1,api-orders-store');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('api.logout');
});
```

### web/.env.example  (65 lines)

```php
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=https://zefanya.shop

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"
```

### web/composer.json  (88 lines)

```php
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "laravel/laravel",
    "type": "project",
    "description": "The skeleton application for the Laravel framework.",
    "keywords": ["laravel", "framework"],
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "laravel/framework": "^13.17",
        "laravel/sanctum": "^4.3",
        "laravel/tinker": "^3.0"
    },
    "require-dev": {
        "fakerphp/faker": "^1.23",
        "laravel/boost": "^2.9",
        "laravel/pail": "^1.2.5",
        "laravel/pao": "^1.0.6",
        "laravel/pint": "^1.27",
        "mockery/mockery": "^1.6",
        "nunomaduro/collision": "^8.6",
        "phpunit/phpunit": "^12.5.12"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "setup": [
            "composer install",
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
            "@php artisan key:generate",
            "@php artisan migrate --force",
            "npm install --ignore-scripts",
            "npm run build"
        ],
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "@php artisan dev"
        ],
        "test": [
            "@php artisan config:clear --ansi @no_additional_args",
            "@php artisan test"
        ],
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi"
        ],
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "post-create-project-cmd": [
            "@php artisan key:generate --ansi",
            "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
            "@php artisan migrate --graceful --ansi"
        ],
        "pre-package-uninstall": [
            "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
        ]
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "php-http/discovery": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## API controllers - app/Http/Controllers/Api

### web/app\Http\Controllers\Api\Auth\LoginController.php  (173 lines)

```php
<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * POST /api/login/buyer
     *
     * Three answers, and the difference between them is the whole reason the
     * app's `login()` returns a result object instead of a boolean: credentials
     * can be perfectly correct while the account is not usable yet.
     *
     *  - `200` with a token when the account is approved;
     *  - `403` with `registration_status` when a gate is still shut, so the app
     *    routes to the screen that gate belongs to (confirm your email, or wait
     *    for review) instead of telling someone their password was wrong;
     *  - `422` on the email field when the credentials do not match.
     *
     * The token is the only credential this client has: no cookie, no session,
     * no CSRF token — see `routes/api.php`.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $buyer = Buyer::where('email', $credentials['email'])->first();

        // One sentence for "no such account" and "wrong password", and one 422
        // under the email field. Distinguishing them would tell anyone who asks
        // which addresses are registered, and the app's form shows the message
        // where the person's next act is — the email they just typed.
        if (! $buyer || ! Hash::check($credentials['password'], $buyer->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($gate = $this->gateResponse($buyer)) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'token' => $buyer->createToken('zefanya-mobile')->plainTextToken,
        ] + $this->buyerPayload($buyer));
    }

    /**
     * GET /api/me
     *
     * What the app asks on a cold start to find out whether its stored token is
     * still good and what the account is allowed to do. It answers with the same
     * gate vocabulary as sign-in on purpose: a token issued to an account that
     * was later rejected, or that never finished verifying, must not read as
     * "signed in" just because the token itself is valid.
     */
    public function show(Request $request): JsonResponse
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold a token
        // today. This is the guard for the day that stops being true.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        if ($gate = $this->gateResponse($buyer)) {
            return $gate;
        }

        return response()->json(['ok' => true] + $this->buyerPayload($buyer));
    }

    /**
     * POST /api/logout
     *
     * Revokes the token that made the request, and only that one: the same
     * person may be signed in on a phone and a tablet, and signing out of one
     * must not sign them out of the other. "A token the server has rejected is
     * worse than no token" — this is how a token stops being accepted.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Signed out.',
        ]);
    }

    /**
     * The gate this account is behind, as a `403`, or `null` when it is through
     * both.
     *
     * Shared by sign-in and `/me` so the two can never disagree about which gate
     * an account is at: an app told "wait for review" by one and "confirm your
     * email" by the other would send the person to the wrong screen half the
     * time, and each would look correct on its own.
     *
     * Email first, then approval — an account can be behind both, and the one to
     * name is the one the person can act on.
     */
    private function gateResponse(Buyer $buyer): ?JsonResponse
    {
        // Before either gate, because a rejected account is behind neither: it
        // has an answer already, and telling someone their application is
        // "pending" when it was turned down is the one lie this flow cannot
        // afford.
        if ($buyer->status === 'rejected') {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
                'message' => 'Your registration was not approved. Please contact support.',
            ], 403);
        }

        if (! $buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                // The same handle a registration returns, so someone who closed
                // the app before entering their code can finish from the login
                // screen rather than owning a token they cannot use.
                'verification_id' => (string) $buyer->id,
                'message' => 'Confirm your email address to continue.',
            ], 403);
        }

        if (! $buyer->isApproved()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
                'message' => 'Your account is waiting for approval.',
            ], 403);
        }

        return null;
    }

    /**
     * Who the account is, in the shape the app's `AuthResult` reads.
     *
     * `registration_status` is the server's own string rather than a boolean,
     * because the two pending gates collapse into one screen state in the app
     * while their *copy* still differs.
     */
    private function buyerPayload(Buyer $buyer): array
    {
        return [
            'role' => 'buyer',
            'name' => $buyer->fullName(),
            'email' => $buyer->email,
            'registration_status' => $buyer->status,
        ];
    }
}
```

### web/app\Http\Controllers\Api\Buyer\RegisterBuyerController.php  (59 lines)

```php
<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RegisterBuyerController extends Controller
{
    /**
     * POST /api/register/buyer
     *
     * The mobile app's door into the same registration the web modal uses: the
     * same `StoreBuyerRegistrationRequest` (so the rules cannot differ between
     * the two doors), the same `Buyer::createFromRegistration()` (so the account
     * cannot differ), and the same `issueEmailVerificationCode()` (so the code
     * cannot differ).
     *
     * The body is multipart, not JSON: `upload_id` is a file part and the server
     * stores it on the private disk. A JSON body cannot carry it, which is why
     * the app's client needs a multipart method rather than a JSON one.
     *
     * 201 rather than the web controller's 200: a row was created, and the app's
     * client treats every 2xx the same way, so this is the server's own
     * vocabulary rather than a shape the client depends on.
     */
    public function store(StoreBuyerRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Private disk (storage/app/ids/...), never public — an ID document is
        // the one thing here that must not be reachable by URL.
        $path = $request->file('upload_id')->store('ids', 'local');

        $buyer = Buyer::createFromRegistration($data, $path);

        $buyer->notify(new BuyerVerifyOtp($buyer->issueEmailVerificationCode()));

        Log::warning('Buyer registration submitted via API, OTP sent', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        return response()->json([
            'ok' => true,
            // The app treats this as opaque: it is the server's row id, and the
            // app neither parses it nor builds one. It is what the confirm and
            // resend routes are keyed on, so without it the app would have
            // nothing to send a code against.
            'verification_id' => (string) $buyer->id,
            'email' => $buyer->email,
            'registration_status' => $buyer->status,
        ], 201);
    }
}
```

### web/app\Http\Controllers\Api\Buyer\VerifyBuyerOtpController.php  (107 lines)

```php
<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyBuyerOtpController extends Controller
{
    /**
     * POST /api/register/buyer/verify-otp/{buyer}
     * Body: { otp: "123456" }
     *
     * The same three-way answer the web modal gets (already verified / wrong or
     * expired code / accepted), with two differences that are about this client
     * rather than about the rule:
     *
     *  - it says which *gate* the account is now at
     *    (`registration_status: pending_approval`) instead of pointing at a
     *    redirect URL, because a phone has no page to be redirected to; and
     *  - the wrong-code and expired cases are both `422` with a `message` and no
     *    `errors` bag, which is what the app's inline error line renders. A field
     *    bag would claim the server said *which* field was wrong when all it said
     *    was no.
     *
     * The `{buyer}` id is not a secret and is not treated as one: it addresses a
     * row whose only usable secret is the 6-digit code, which is stored as a
     * sha256 hash, expires in 10 minutes, and is rate-limited by the route.
     */
    public function verify(Request $request, Buyer $buyer): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        // Already past the gate: answering "yes" rather than refusing means a
        // double-tap, or a resend that arrived after the confirmation, cannot
        // look like a failure to the person who just succeeded.
        if ($buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => true,
                'already_verified' => true,
                'registration_status' => $buyer->status,
                'message' => 'Your email is already confirmed.',
            ]);
        }

        if (! $buyer->verifyEmailCode($data['otp'])) {
            return response()->json([
                'ok' => false,
                'message' => 'That code doesn\'t match. Please try again.',
            ], 422);
        }

        // Checked after the code, not before: "this code has expired" tells
        // someone a real code was once valid, which is information only worth
        // giving to whoever holds that code.
        if ($buyer->isVerificationLinkExpired()) {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'message' => 'This code has expired. Please request a new one.',
            ], 422);
        }

        $buyer->markEmailVerified();

        return response()->json([
            'ok' => true,
            'registration_status' => $buyer->status,
            'message' => 'Email confirmed. Your application is now with our team.',
        ]);
    }

    /**
     * POST /api/register/buyer/verify-otp/{buyer}/resend
     *
     * Issues a fresh code and emails it. Returning no code and no status is
     * deliberate: the app's screen shows the server's own sentence ("a new code
     * has been sent") and nothing else changes about the account.
     */
    public function resend(Buyer $buyer): JsonResponse
    {
        if ($buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'message' => 'This account is already verified.',
            ], 422);
        }

        $buyer->notify(new BuyerVerifyOtp($buyer->issueEmailVerificationCode()));

        Log::warning('Buyer OTP verification code resent via API', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'A new code has been sent.',
        ]);
    }
}
```

### web/app\Http\Controllers\Api\Cart\VoucherController.php  (43 lines)

```php
<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherResource;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/vouchers` — the vouchers a buyer may choose from.
 *
 * Only the ones a person could actually use are listed: active, unexpired, and
 * under their usage limit. `Voucher::rejectionReason()` is the single place that
 * decides this for a *given subtotal*, and it cannot be reused here because a
 * catalogue read has no subtotal to test the minimum spend against — so this
 * applies the three subtotal-independent rules and leaves the minimum spend to be
 * checked when a voucher is actually applied. The app renders a voucher's minimum
 * spend rather than enforcing it, so the split costs nothing.
 *
 * The list is public for the same reason the catalogue is: it is shop-window
 * content, and the web's cart page sits behind a buyer session only because a
 * cart does.
 */
class VoucherController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q
                ->whereNull('usage_limit')
                ->orWhereColumn('times_used', '<', 'usage_limit'))
            ->orderBy('id')
            ->get();

        return VoucherResource::collection($vouchers);
    }
}
```

### web/app\Http\Controllers\Api\Catalog\CategoryController.php  (32 lines)

```php
<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Shared\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/categories` — the chips the app's category row renders.
 *
 * The list is curated by migration (`create_categories_table` inserts the
 * fourteen rows and says not to build a CRUD for them), so it is ordered by id,
 * which is the curation's own order. Ordering by label would be tidier
 * alphabetically and would throw away the one thing the file says matters.
 *
 * The app's own list is `['All', 'Fashion', 'Home & Living', 'Beauty']` — four
 * names, three of which do not exist here. The app prepends its `'All'` sentinel
 * itself (it is a UI affordance, not a row) and renders whatever this returns, so
 * a live build shows the real fourteen rather than the mock's four. That
 * difference is the answer to OPEN QUESTIONS §0.3, not a bug to paper over.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->orderBy('id')->get(),
        );
    }
}
```

### web/app\Http\Controllers\Api\Catalog\ProductController.php  (117 lines)

```php
<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The app's read-only door onto the catalogue: `GET /api/products`.
 *
 * ## Public, like the web's own browsing pages
 *
 * Nothing here requires a token. A catalogue is not per-buyer, and the web's
 * equivalents (`routes/buyer.php`'s `/buyer/products`) sit *outside* their
 * `auth:buyer` group for the same reason. Requiring a token would also make a
 * shop window need an account, which is a product decision nobody made.
 *
 * ## What "publicly visible" means, in one place
 *
 * A product is listed and openable only when it is `active` **and** its seller
 * has been approved. Both halves matter: the seller's own dashboard filters by
 * `status` alone (a seller may look at their own drafts), and the web buyer
 * product page checks `status` alone — but an unapproved seller's product has no
 * business being browsable, so both the list and the single read apply both
 * rules, which is why they are stated here once instead of being spread over two
 * queries that could drift.
 *
 * This is also why `scopeActiveInStock` is *not* used: it would additionally hide
 * a sold-out product, and an item that silently vanishes from a catalogue is
 * indistinguishable from a bug. Stock is the seller's business; the app has no
 * stock UI to explain an absence with.
 *
 * ## No pagination, deliberately
 *
 * The app's repository contract is "the catalogue", with no cursor, page or
 * `hasMore` in it (`CatalogRepository.products()` returns a list). A paginated
 * response would give the app its first page and quietly drop the rest — the
 * failure mode of a missing page is not an error, it is a shorter catalogue. So
 * this returns everything, and pagination arrives the day the app can ask for a
 * page. With a real catalogue behind it that is the first thing to change.
 */
class ProductController extends Controller
{
    /**
     * GET /api/products?q=&category=&seller=
     *
     * The three filters are the three the app can ask for
     * (`CatalogQuery`: `search`, `category`, `seller`), and all three are matched
     * the way the app's mock catalogue matches them, so switching a build from
     * mock to live does not change which products a query returns.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('status', 'active')
            ->whereHas('seller', fn (Builder $seller) => $seller->where('status', 'approved'))
            // Eager-loaded because the resource reads all three: without this the
            // list would issue three queries per product.
            ->with(['images', 'seller', 'category']);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            // Name and category, not the description. The mock's comment is the
            // reason and it holds here too: matching the description makes a
            // two-word query return nearly everything, which reads as broken
            // rather than thorough.
            $query->where(function (Builder $match) use ($like) {
                $match->where('name', 'like', $like)
                    ->orWhereHas('category', fn (Builder $category) => $category->where('label', 'like', $like));
            });
        }

        // A category *label*: the app sends the same string it renders on the chip
        // and the server resolves it, so the id<->name mapping stays on the side
        // that owns the rows. An unknown label is not an error — it is a filter
        // that matches nothing, which the app already renders as an empty state.
        $category = trim((string) $request->input('category', ''));
        if ($category !== '') {
            $query->whereHas('category', fn (Builder $c) => $c->where('label', $category));
        }

        // A seller's *shop name* (`Product.sellerName` in the app), which is what
        // the shop screen holds.
        $seller = trim((string) $request->input('seller', ''));
        if ($seller !== '') {
            $query->whereHas('seller', fn (Builder $s) => $s->where('business_name', $seller));
        }

        // Newest first: an unbounded list still needs a stable order, and a
        // catalogue's natural one is "what was listed most recently".
        return ProductResource::collection($query->latest('id')->get());
    }

    /**
     * GET /api/products/{product}
     *
     * A product the buyer may not see answers 404 rather than 403: "this was
     * never here" is the truth a shop window tells about a draft item, and it
     * cannot be used to probe which ids exist.
     */
    public function show(Product $product): ProductResource
    {
        $product->loadMissing(['images', 'seller', 'category']);

        abort_unless(
            $product->status === 'active' && $product->seller?->isApproved(),
            404,
        );

        return new ProductResource($product);
    }
}
```

### web/app\Http\Controllers\Api\Order\OrderController.php  (242 lines)

```php
<?php

namespace App\Http\Controllers\Api\Order;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\CartSummary;
use App\Services\OrderPlacer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The buyer's own order history: `GET /api/orders`.
 *
 * ## The first authenticated read on this API
 *
 * Everything else here either precedes a token (registration, sign-in) or is public
 * because it is a shop window (the catalogue). An order history is per-person by
 * definition, so it sits behind `auth:sanctum` — and it is the reason `/me` exists
 * beside it: a token that lists orders has to be checkable.
 *
 * ## The query starts at the join, so there is nothing to tamper with
 *
 * It begins at `seller_orders` because the unit the app lists is a parcel (see
 * `OrderResource`), and it is scoped through `orders.buyer_id` rather than through
 * an id from the request. That is deliberate: a request cannot name another buyer's
 * order, so there is no ownership check to forget, and a `404` is not even needed to
 * hide one.
 *
 * ## Newest first, by the checkout's own timestamp
 *
 * The parent order's `created_at`, not the seller order's. One checkout writes all
 * of its parcels in a single transaction, so ordering by the child's own timestamp
 * would reshuffle the parcels of one checkout against each other — technically
 * newer, and confusing to read.
 */
class OrderController extends Controller
{
    /**
     * The placing rules are the web checkout's, to the centavo.
     *
     * Which is the point of the service: the app's cart lives on the phone, so
     * this door can only hand over **lines** — a product and a quantity each —
     * and everything authoritative (the price, the stock, the discount split,
     * the shipping snapshot) is computed on the server from the locked rows. The
     * request chooses *what* is bought and *how many*, never *how much*.
     */
    public function __construct(private readonly OrderPlacer $orderPlacer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold one today.
        // This is the guard for the day that stops being true.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $orders = SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_id', $buyer->id)
            ->orderByDesc('orders.created_at')
            ->orderBy('seller_orders.id')
            ->select('seller_orders.*')
            // Eager-loaded because the resource reads all three; without it a
            // history of N parcels would issue 3N queries.
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * `POST /api/orders` — place the order for the phone's basket.
     *
     * ## The reply is the same shape as the history, so the app has one parser
     *
     * `201` with the new order's **parcels**, each exactly as `OrderResource`
     * already renders them for `GET /orders`. The app's confirmation and its
     * history therefore read one payload between them, and placing an order needs
     * no second spelling of the shape on the Dart side.
     *
     * Order-level money — which a parcel cannot carry, because a discount is split
     * *across* parcels — rides beside them in `meta`, so a basket that applied ₱200
     * off gets the total it should show, in centavos, next to the same rows the
     * history returns. Additive: a client that knows only `data` parses this reply
     * unchanged.
     *
     * ## `422` is "the basket is no longer placeable", not bad syntax
     *
     * A line that has sold out, gone inactive or vanished answers `422` with the
     * service's own sentence, and nothing is written: the checkout transaction
     * rolls back whole, so there is no order, no phantom stock decrement and no
     * ledger row. That is a *retryable with a fresh basket* answer, which the app
     * can show with one "Refresh basket" act — hence a refusal meant to be read
     * rather than an opaque `500`.
     *
     * A voucher code that does not apply is refused the same way, and deliberately
     * **not** dropped. The web door can afford to drop one and carry on, because
     * its next page re-renders the totals; a phone showing "₱200 off" would
     * otherwise place the order, be charged full price, and display the wrong
     * number. Refusing makes a stale basket visible instead of letting it quietly
     * change the price.
     *
     * ## The one thing this route does not yet do
     *
     * It is not idempotent: two taps that both reach here are two orders, as two
     * taps on the web's button are. The web relies on a client-side guard (disable
     * the button while the request is in flight); an idempotency key would be the
     * server's own answer, and is not invented here.
     */
    public function store(Request $request): JsonResponse
    {
        $buyer = $request->user();

        // Same guard as index(): `auth:sanctum` accepts any tokenable, and only
        // buyers can hold one today.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var list<array{product_id: int, quantity: int}> $lines */
        $lines = collect($data['items'])
            ->map(fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ])
            ->values()
            ->all();

        // A voucher is a *door's* question (which code did the shopper type?) while
        // the arithmetic is shared, so it is resolved here from the code and handed
        // over ready to use — exactly as the web's checkout does.
        $voucher = null;
        $code = trim((string) ($data['voucher_code'] ?? ''));

        if ($code !== '' && ($refusal = $this->resolveVoucher($code, $lines, $voucher)) !== null) {
            return $refusal;
        }

        try {
            $order = $this->orderPlacer->place($buyer, $lines, $voucher);
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // `order:id,reference` for the reference each parcel reports: loaded
        // explicitly rather than relied on, so the resource cannot pick it up from
        // a parent that merely happens to be in memory.
        $parcels = $order->sellerOrders()
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($parcels)
            ->additional([
                'meta' => [
                    'order_id' => (string) $order->id,
                    'reference' => (string) $order->reference,
                    'subtotal_minor' => (int) $order->subtotal_minor,
                    'discount_minor' => (int) $order->discount_minor,
                    'total_minor' => (int) $order->total_minor,
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Resolve the submitted code, or return the refusal to send back.
     *
     * The minimum-spend test needs a subtotal, and the authoritative one is only
     * knowable under the lock — so this reads the prices first, the same pre-lock
     * read the web door makes, and shapes the lines as `CartSummary` expects. Two
     * consequences, both deliberate:
     *
     *  - a price that moves between this read and the transaction can only make
     *    the voucher *stricter* (a smaller basket is likelier to miss a minimum),
     *    never cheaper, because the discount actually charged is computed from the
     *    locked rows inside the service;
     *  - a line whose product no longer exists contributes nothing here, so a
     *    minimum-spend voucher may be refused on a basket that could not have been
     *    placed anyway.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    private function resolveVoucher(string $code, array $lines, ?Voucher &$voucher): ?JsonResponse
    {
        $products = Product::query()
            ->withTrashed()
            ->with('seller')
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        /** @var Collection<int, CartItem> $items */
        $items = collect($lines)
            ->map(function (array $line) use ($products): CartItem {
                $item = new CartItem([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ]);

                // Never saved: these stand in for cart rows the phone owns, so the
                // money keeps its one spelling (`CartItem::lineTotalCentavos`).
                return $item->setRelation('product', $products->get($line['product_id']));
            })
            ->filter(fn (CartItem $item): bool => $item->product !== null)
            ->values();

        $notice = null;
        $voucher = Voucher::resolveCode($code, (new CartSummary($items))->subtotalCentavos, $notice);

        if ($voucher !== null) {
            return null;
        }

        return response()->json([
            'message' => $notice ?? 'This voucher code is no longer valid.',
            'voucher_rejected' => $code,
        ], 422);
    }
}
```

## Shared services - both doors call these

### web/app\Services\CartSummary.php  (206 lines)

```php
<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Seller\Seller;
use App\Models\Voucher;
use Illuminate\Support\Collection;

/**
 * Computes cart totals in integer centavos to avoid float precision errors.
 *
 * Used by:
 *  - CartController@index   (full page render)
 *  - CartController@update  (PATCH — returns updated JSON totals)
 *  - CartController@summary (GET — lightweight JSON refresh after selection changes)
 *  - CheckoutController@index (to confirm totals before placing an order)
 *
 * Selection semantics (important):
 *   $selectedIds === null  → "no selection was sent"          → every available item counts
 *   $selectedIds === []    → "the buyer deselected everything" → totals are zero
 * Never conflate the two: it is what makes "Proceed to Checkout" disable correctly.
 */
final class CartSummary
{
    /** @var Collection<int, CartItem>  Items that are fully available */
    public readonly Collection $availableItems;

    /** @var Collection<int, CartItem>  Items that are unavailable (dimmed rows) */
    public readonly Collection $unavailableItems;

    /** @var Collection<int, array{seller: Seller, items: Collection}>  Grouped by seller */
    public readonly Collection $sellerGroups;

    public readonly int $subtotalCentavos;

    public readonly int $discountCentavos;

    public readonly int $totalCentavos;

    public readonly ?Voucher $voucher;

    /** @var Collection<int, CartItem>  Every line in the cart, available or not */
    private readonly Collection $allItems;

    /** @var array<int>|null null = all available items, [] = nothing selected */
    private readonly ?array $selectedIds;

    public function __construct(
        /** @param Collection<int, CartItem> $allItems All cart items, already loaded with product.images + product.seller + product.category */
        Collection $allItems,
        ?Voucher $voucher = null,
        /** @param array<int>|null $selectedIds Cart item ids to include in the totals */
        ?array $selectedIds = null,
    ) {
        $this->allItems = $allItems;

        $this->availableItems = $allItems
            ->filter(fn (CartItem $i): bool => $i->isAvailable())
            ->values();

        $this->unavailableItems = $allItems
            ->reject(fn (CartItem $i): bool => $i->isAvailable())
            ->values();

        $this->selectedIds = $selectedIds;

        // Group available items by seller for the page layout
        $this->sellerGroups = $this->availableItems
            ->groupBy(fn (CartItem $i): int => (int) $i->product->seller_id)
            ->map(fn (Collection $group): array => [
                'seller' => $group->first()->product->seller,
                'items' => $group->values(),
            ])
            ->values();

        // Totals computed over selected items only
        $this->subtotalCentavos = (int) $this->selectedItems()
            ->sum(fn (CartItem $i): int => $i->lineTotalCentavos());

        $this->voucher = $voucher;
        $this->discountCentavos = $voucher !== null
            ? $voucher->discountCentavos($this->subtotalCentavos)
            : 0;

        $this->totalCentavos = max(0, $this->subtotalCentavos - $this->discountCentavos);
    }

    // ── Selection helpers ───────────────────────────────────────────────────

    /** @return Collection<int, CartItem> */
    private function selectedItems(): Collection
    {
        if ($this->selectedIds === null) {
            return $this->availableItems;
        }

        return $this->availableItems->whereIn('id', $this->selectedIds)->values();
    }

    /** Total quantity of the SELECTED items (the "N items" pill). */
    public function selectedQuantity(): int
    {
        return (int) $this->selectedItems()->sum('quantity');
    }

    /** Number of distinct SELECTED cart lines. */
    public function selectedLineCount(): int
    {
        return $this->selectedItems()->count();
    }

    /** Number of distinct lines the buyer could tick (drives "Select all (N items)"). */
    public function availableLineCount(): int
    {
        return $this->availableItems->count();
    }

    /**
     * Total quantity of EVERY line, available or not — this is the navbar badge,
     * so the shopper still sees items that went out of stock.
     */
    public function cartQuantity(): int
    {
        return (int) $this->allItems->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->allItems->isEmpty();
    }

    // ── Formatted helpers (₱X,XXX.XX) ───────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotalCentavos / 100, 2);
    }

    public function formattedDiscount(): string
    {
        return '-₱'.number_format($this->discountCentavos / 100, 2);
    }

    public function formattedTotal(): string
    {
        return '₱'.number_format($this->totalCentavos / 100, 2);
    }

    /** Applied-voucher chip text, e.g. "ZEFANYANEW (-₱200.00)". */
    public function voucherChipLabel(): string
    {
        if ($this->voucher === null) {
            return '';
        }

        return $this->voucher->code.' ('.$this->formattedDiscount().')';
    }

    /**
     * Per-line totals for every available item, so the page JS can refresh a
     * single row without recomputing money client-side.
     *
     * @return array<int, array{raw: int, formatted: string}>
     */
    public function lineTotals(): array
    {
        return $this->availableItems
            ->mapWithKeys(fn (CartItem $i): array => [
                $i->id => [
                    'raw' => $i->lineTotalCentavos(),
                    'formatted' => $i->formattedLineTotal(),
                ],
            ])
            ->all();
    }

    /**
     * Return a plain array for JSON responses so controllers don't have to
     * reassemble data manually. `$voucherNotice` carries a message when a
     * previously applied voucher was dropped during recalculation.
     *
     * @return array<string, mixed>
     */
    public function toJson(?string $voucherNotice = null): array
    {
        return [
            'subtotal' => $this->formattedSubtotal(),
            'discount' => $this->formattedDiscount(),
            'total' => $this->formattedTotal(),
            'subtotal_raw' => $this->subtotalCentavos,
            'discount_raw' => $this->discountCentavos,
            'total_raw' => $this->totalCentavos,
            'selected_qty' => $this->selectedQuantity(),
            'selected_lines' => $this->selectedLineCount(),
            'available_lines' => $this->availableLineCount(),
            'cart_count' => $this->cartQuantity(),
            'line_totals' => $this->lineTotals(),
            'has_voucher' => $this->voucher !== null,
            'voucher_code' => $this->voucher?->code,
            'voucher_label' => $this->voucher !== null ? $this->voucherChipLabel() : null,
            'voucher_notice' => $voucherNotice,
            'is_empty' => $this->isEmpty(),
        ];
    }
}
```

### web/app\Services\OrderPlacer.php  (291 lines)

```php
<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Placing an order: the one spelling of what a checkout writes.
 *
 * ## Why these rules are a service rather than a controller method
 *
 * They began private to `Buyer\CheckoutController`, which is the *web* door: it
 * reads a selection parked in the session, over `cart_items` rows the web app owns.
 * The mobile app has no server-side cart — its cart lives on the device — so when
 * the API grew `POST /orders` there was nothing for it to hand over, and the money
 * rules existed exactly once, in a place the app could not reach.
 *
 * So they moved here and take **lines**: a product id and a quantity each. From
 * whichever door, everything authoritative is still computed server-side from the
 * database — a request contributes *which* product and *how many*, never a price.
 * That is the rule the controller's own doc already stated, and it is why a body
 * cannot talk this service into a cheaper order.
 *
 * ## The transaction, and what is inside it
 *
 * Order, seller orders, order items, payments, the locked stock decrement and the
 * append-only inventory ledger all happen in one transaction, so a failure rolls
 * everything back: no partial orders, no phantom stock changes. Product rows are
 * locked in id order, which is what stops two concurrent checkouts deadlocking.
 */
final class OrderPlacer
{
    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @param  Voucher|null  $voucher  Already resolved by the caller — the web reads
     *                                 it from the session, the API from the request —
     *                                 because *which* voucher is a door's question
     *                                 while `Voucher::resolveCode` is shared.
     *
     * @throws CheckoutException when a line is no longer buyable under the lock
     */
    public function place(Buyer $buyer, array $lines, ?Voucher $voucher = null): Order
    {
        if ($lines === []) {
            throw new CheckoutException('There is nothing to place an order for.');
        }

        return DB::transaction(function () use ($buyer, $lines, $voucher): Order {
            $products = $this->lockProducts($lines);

            // The shape `CartSummary` reads — a product and a quantity — built from
            // the *locked* rows so the money and the stock guard agree about what was
            // bought. These are not cart rows and are never saved: the web's cart is
            // the web's, and the app has none.
            $items = collect();

            foreach ($lines as $line) {
                /** @var Product|null $product */
                $product = $products->get($line['product_id']);

                $this->assertBuyable($product, $line['quantity']);

                $item = new CartItem([
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                ]);

                $items->push($item->setRelation('product', $product));
            }

            $summary = new CartSummary($items, $voucher);

            $order = new Order;
            $order->forceFill([
                'buyer_id' => $buyer->id,
                'reference' => Order::generateUniqueReference(),
                'shipping_address' => $this->addressSnapshot($buyer),
                'subtotal_minor' => $summary->subtotalCentavos,
                'discount_minor' => $summary->discountCentavos,
                'total_minor' => $summary->totalCentavos,
                'status' => OrderStatus::Placed,
            ])->save();

            // One seller_order per shop; the order-level voucher discount is
            // allocated proportionally so the per-shop COD payments sum exactly to
            // the discounted order total (largest-remainder method).
            $discounts = $this->allocateDiscount(
                $summary->discountCentavos,
                $summary->sellerGroups
                    ->map(fn (array $group): int => $this->groupSubtotal($group['items']))
                    ->all(),
            );

            // Keyed by the line object itself. This was `$sellerOrderIdsByCartItemId`,
            // keyed on `cart_items` — which is exactly what pinned these rules to the
            // web: a line from the app has no cart row to be keyed by.
            $sellerOrderIdsByLine = [];

            foreach ($summary->sellerGroups as $index => $group) {
                $sellerOrder = new SellerOrder;
                $sellerOrder->forceFill([
                    'order_id' => $order->id,
                    'seller_id' => $group['seller']->id,
                    'subtotal_minor' => $this->groupSubtotal($group['items']),
                    'status' => OrderStatus::Placed,
                ])->save();

                foreach ($group['items'] as $item) {
                    /** @var Product $product */
                    $product = $item->product;

                    OrderItem::query()->create([
                        'seller_order_id' => $sellerOrder->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $item->quantity,
                        // Price snapshot at order time, from the same source
                        // `CartSummary` used, so totals and lines always agree.
                        'price_minor' => (int) bcmul((string) $product->price, '100', 0),
                    ]);

                    $sellerOrderIdsByLine[spl_object_id($item)] = $sellerOrder->id;
                }

                $payment = new Payment;
                $payment->forceFill([
                    'seller_order_id' => $sellerOrder->id,
                    'amount_minor' => $sellerOrder->subtotal_minor - $discounts[$index],
                    'method' => PaymentMethod::Cod,
                    'status' => PaymentStatus::Pending,
                ])->save();
            }

            // Decrement stock + write the append-only ledger, still under the same
            // row locks, inside the same transaction.
            foreach ($items as $item) {
                /** @var Product $product */
                $product = $products->get($item->product_id);
                $before = (int) $product->stock_quantity;

                $product->stock_quantity = $before - $item->quantity;
                $product->save();

                InventoryTransaction::query()->create([
                    'product_id' => $product->id,
                    'seller_order_id' => $sellerOrderIdsByLine[spl_object_id($item)],
                    'quantity_before' => $before,
                    'quantity_after' => $product->stock_quantity,
                    'delta' => -$item->quantity,
                    'reason' => 'checkout',
                ]);
            }

            return $order;
        });
    }

    /**
     * The products in [lines], locked for update in id order.
     *
     * Id order rather than arrival order: two checkouts holding the same two
     * products in opposite orders would otherwise deadlock.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @return Collection<int, Product>
     */
    private function lockProducts(array $lines): Collection
    {
        return Product::query()
            ->whereIn('id', array_column($lines, 'product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The authoritative gate on whether a line can be bought.
     *
     * The caller's own pre-check is advisory — a cart page renders before anyone
     * clicks — so this one runs under the lock, and the stock it reads is the stock
     * nobody else can change until this transaction finishes.
     */
    private function assertBuyable(?Product $product, int $quantity): void
    {
        if ($product === null
            || $product->trashed()
            || $product->status !== 'active'
            || $product->stock_quantity < $quantity) {
            throw new CheckoutException(
                'Some items just went out of stock while placing your order. Please review your cart and try again.'
            );
        }
    }

    /**
     * One shop's subtotal, in centavos.
     *
     * @param  Collection<int, CartItem>  $items
     */
    private function groupSubtotal(Collection $items): int
    {
        return (int) $items->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
    }

    /**
     * Split an order-level voucher discount across seller orders proportionally to
     * their subtotals (largest-remainder method), so the per-shop COD payments sum
     * exactly to the discounted order total.
     *
     * @param  array<int, int>  $subtotals  Centavos per seller group, insertion order preserved
     * @return array<int, int> Discount in centavos per seller group, same keys
     */
    private function allocateDiscount(int $discount, array $subtotals): array
    {
        $total = array_sum($subtotals);

        if ($total <= 0 || $discount <= 0) {
            return array_map(fn (): int => 0, $subtotals);
        }

        $allocated = [];
        $remainders = [];

        foreach ($subtotals as $key => $subtotal) {
            $exact = ($discount * $subtotal) / $total;
            $allocated[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $allocated[$key];
        }

        // Hand the leftover centavos to the largest fractional remainders
        // (floor() can leave at most one centavo per group behind).
        $left = $discount - array_sum($allocated);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }

            $allocated[$key]++;
            $left--;
        }

        return $allocated;
    }

    /**
     * Immutable snapshot of the buyer's profile address at order time
     * (orders.shipping_address is JSON, deliberately not a live FK).
     *
     * @return array<string, string|null>
     */
    private function addressSnapshot(Buyer $buyer): array
    {
        $parts = array_filter([
            $buyer->house_number,
            $buyer->street,
            $buyer->barangay_name,
            $buyer->municipality_name,
            $buyer->province_name,
            $buyer->address_detail,
        ]);

        return [
            'name' => $buyer->fullName(),
            'phone' => $buyer->contact_no,
            'province' => $buyer->province_name,
            'municipality' => $buyer->municipality_name,
            'barangay' => $buyer->barangay_name,
            'street' => $buyer->street,
            'house_number' => $buyer->house_number,
            'address_detail' => $buyer->address_detail,
            'full' => implode(', ', $parts),
        ];
    }
}
```

## The other door - the web checkout, for comparison

### web/app\Http\Controllers\Buyer\CheckoutController.php  (199 lines)

```php
<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\CartSummary;
use App\Services\OrderPlacer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /** Session key holding the ids checkout is allowed to see. */
    public const SESSION_KEY = 'checkout_item_ids';

    /**
     * The placing rules live in `OrderPlacer`, shared with the app's door on the
     * same API: what the two doors can share is **lines**, not cart rows, because
     * this one's cart is server-side and the phone's is on the phone.
     */
    public function __construct(private readonly OrderPlacer $orderPlacer) {}

    /**
     * Show checkout for the buyer's SELECTED cart items.
     *
     * Accepts `?items[]=…` from the cart page. Anything that is not one of the
     * buyer's own available cart lines is ignored, so a tampered id can never
     * expose another buyer's line. When nothing is passed the whole available
     * cart is used.
     *
     * NOTE: the checkout screen itself is still a stub — this only resolves and
     * parks the selection (query + session) so the UI phase can build on it.
     */
    public function index(Request $request): View
    {
        $buyer = auth('buyer')->user();

        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category'])])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $available = $items
            ->filter(fn (CartItem $item): bool => $item->isAvailable())
            ->values();

        // null = "no selection sent" → CartSummary covers every available item.
        $selectedIds = null;

        if ($request->has('items')) {
            $requested = array_filter(
                array_map('intval', (array) $request->input('items', [])),
                fn (int $id): bool => $id > 0,
            );

            $selectedIds = $available->whereIn('id', $requested)->pluck('id')->all();
        }

        // Park the resolved selection for the future checkout.store step.
        session([self::SESSION_KEY => $selectedIds ?? $available->pluck('id')->all()]);

        $voucherNotice = null;
        $voucher = Voucher::resolveCode(
            session('cart_voucher'),
            (new CartSummary($items, null, $selectedIds))->subtotalCentavos,
            $voucherNotice,
        );

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return view('Buyer.Checkout.index', [
            'items' => $available,
            'summary' => $summary,
            'voucher' => $voucher,
            'voucherNotice' => $voucherNotice,
        ]);
    }

    /**
     * Place the order for the buyer's parked checkout selection.
     *
     * Everything authoritative is re-resolved server-side from the session +
     * database: the selection (parked by index()), the voucher code, and all
     * money via CartSummary. Nothing in the request body is trusted for
     * pricing. The whole write happens in one transaction with locked stock.
     */
    public function store(Request $request): RedirectResponse
    {
        $buyer = auth('buyer')->user();

        $parkedIds = array_values(array_unique(array_map('intval', (array) session(self::SESSION_KEY, []))));

        if ($parkedIds === []) {
            return redirect()->route('buyer.cart.index')
                ->with('error', 'Your checkout selection is empty. Please select items in your cart first.');
        }

        // Re-resolve from the LIVE cart: a parked id may have been removed in
        // another tab, and ownership is re-asserted by scoping to the buyer's
        // own cart rows (a tampered id can never check out someone else's line).
        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category'])])
            ->whereIn('id', $parkedIds)
            ->get()
            ->keyBy('id');

        $problems = [];

        foreach ($parkedIds as $id) {
            $item = $items->get($id);

            if ($item === null) {
                $problems[] = 'an item no longer in your cart';

                continue;
            }

            $product = $item->product;

            if ($product === null || ! $item->isAvailable()) {
                $problems[] = $product?->name ?? 'an unavailable item';

                continue;
            }

            if ($item->quantity > $product->stock_quantity) {
                $problems[] = "{$product->name} (only {$product->stock_quantity} left)";
            }
        }

        if ($problems !== []) {
            return redirect()->route('buyer.cart.index')
                ->with('error', 'Some items are no longer available: '.implode(', ', array_unique($problems)).'. Please review your cart.');
        }

        // Voucher re-resolution identical to index(): resolved against the
        // selection's subtotal, dropped with a notice when invalid/expired.
        $notice = null;
        $base = new CartSummary($items, null, null);
        $voucher = Voucher::resolveCode(session('cart_voucher'), $base->subtotalCentavos, $notice);

        try {
            // Lines rather than cart rows: the service is shared with the API, whose
            // cart lives on the phone. The voucher is resolved above (a door's own
            // question) and every amount is computed inside, from the locked rows.
            $order = $this->orderPlacer->place(
                $buyer,
                $items
                    ->map(fn (CartItem $item): array => [
                        'product_id' => (int) $item->product_id,
                        'quantity' => (int) $item->quantity,
                    ])
                    ->values()
                    ->all(),
                $voucher,
            );
        } catch (CheckoutException $e) {
            return redirect()->route('buyer.cart.index')->with('error', $e->getMessage());
        }

        // Success: remove the purchased lines and clear the checkout/voucher
        // session state. Only the SELECTED ids are removed — anything else
        // left in the cart survives for the next checkout.
        $buyer->cartItems()->whereIn('id', $parkedIds)->delete();

        session()->forget([self::SESSION_KEY, 'cart_voucher']);

        $redirect = redirect()->route('buyer.checkout.success', $order);

        if ($notice !== null) {
            $redirect = $redirect->with('voucher_notice', $notice);
        }

        return $redirect;
    }

    /**
     * Minimal order-confirmation page (full tracking UI is Phase 3).
     */
    public function success(Request $request, Order $order): View
    {
        $buyer = auth('buyer')->user();

        abort_unless($order->buyer_id === $buyer->id, 404);

        $order->load(['sellerOrders.seller', 'sellerOrders.orderItems.product', 'sellerOrders.payment']);

        return view('Buyer.Checkout.success', ['order' => $order]);
    }
}
```

### web/app\Http\Controllers\Buyer\CartController.php  (310 lines)

```php
<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\CartSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CartController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Set when a previously applied voucher was dropped during re-validation. */
    private ?string $voucherNotice = null;

    /** Load the buyer's cart items with all relations needed by the views. */
    private function loadItems(): Collection
    {
        return auth('buyer')->user()
            ->cartItems()
            ->with([
                'product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve the `selected_ids` input.
     *
     * Returns null when the key is absent (meaning "no selection was sent" —
     * the summary then covers every available item), and an array of positive
     * ids otherwise. An empty array therefore means "nothing is selected",
     * which is what allows the checkout button to disable and the total to go
     * to zero.
     *
     * @return array<int>|null
     */
    private function selectedIdsFrom(Request $request): ?array
    {
        if (! $request->has('selected_ids')) {
            return null;
        }

        return array_values(array_filter(
            array_map('intval', (array) $request->input('selected_ids', [])),
            fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * Recompute the cart totals and return them as JSON, so every mutating
     * endpoint answers with the same shape as `summary`.
     *
     * @param  array<string, mixed>  $extra
     */
    private function summaryResponse(Collection $items, ?array $selectedIds = null, array $extra = []): JsonResponse
    {
        $voucher = $this->resolveVoucher(
            (new CartSummary($items, null, $selectedIds))->subtotalCentavos
        );

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return response()->json(array_merge(
            $summary->toJson($this->voucherNotice),
            $extra,
        ));
    }

    /**
     * Resolve the voucher stored in the session (re-validated on every request
     * against the current subtotal). An expired, inactive or below-min-spend
     * voucher is dropped and its reason is captured in $voucherNotice so the
     * page / JSON response can explain why.
     */
    private function resolveVoucher(int $subtotalCentavos): ?Voucher
    {
        $code = session('cart_voucher');

        if (! $code) {
            return null;
        }

        $voucher = Voucher::where('code', strtoupper($code))->first();

        $reason = $voucher === null
            ? 'This voucher code is no longer valid.'
            : $voucher->rejectionReason($subtotalCentavos);

        if ($reason !== null) {
            session()->forget('cart_voucher');
            $this->voucherNotice = $reason;

            return null;
        }

        return $voucher;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $items = $this->loadItems();
        $voucher = $this->resolveVoucher((new CartSummary($items))->subtotalCentavos);
        $summary = new CartSummary($items, $voucher);

        return view('Buyer.Cart.index', [
            'items' => $items,
            'summary' => $summary,
            'voucher' => $voucher,
            'voucherNotice' => $this->voucherNotice,
        ]);
    }

    // ── add (POST /buyer/cart/add — open to guests via the 401 pattern) ──────

    public function add(Request $request): JsonResponse
    {
        if (! auth('buyer')->check()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        // `status = active` + the SoftDeletes default scope already excludes
        // drafts and deleted products; stock must also be positive, otherwise
        // an out-of-stock product would create a quantity-0 cart line.
        $product = Product::where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->find($validated['product_id']);

        if (! $product) {
            return response()->json(['error' => 'This product is not available.'], 422);
        }

        $buyer = auth('buyer')->user();

        // Read-merge-write inside a transaction with a row lock. This protects
        // the unique(buyer_id, product_id) constraint from concurrent adds.
        // NOTE: this never touches products.stock_quantity — a cart is not a
        // stock reservation, so no inventory_transactions row is written here.
        $cartCount = DB::transaction(function () use ($buyer, $product, $validated): int {
            $existing = CartItem::where('buyer_id', $buyer->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $requested = (int) $validated['quantity'];
            $newQty = min(($existing?->quantity ?? 0) + $requested, $product->stock_quantity);

            if ($existing !== null) {
                $existing->update(['quantity' => $newQty]);
            } else {
                CartItem::create([
                    'buyer_id' => $buyer->id,
                    'product_id' => $product->id,
                    'quantity' => min($requested, $product->stock_quantity),
                ]);
            }

            return (int) CartItem::where('buyer_id', $buyer->id)->sum('quantity');
        });

        return response()->json(['success' => true, 'cart_count' => $cartCount]);
    }

    // ── update (PATCH /buyer/cart/{id}) ──────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $item = CartItem::where('id', $id)
            ->where('buyer_id', auth('buyer')->id())
            ->firstOrFail();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        // The product may have been soft-deleted since it was added, in which
        // case $item->product is null and we must not touch the quantity.
        $product = Product::withTrashed()->find($item->product_id);

        if ($product === null) {
            return response()->json(['error' => 'This product no longer exists.'], 422);
        }

        if ($product->trashed() || $product->status !== 'active' || $product->stock_quantity <= 0) {
            return response()->json(['error' => 'This item is no longer available.'], 422);
        }

        $item->update([
            'quantity' => min((int) $validated['quantity'], $product->stock_quantity),
        ]);

        $item->refresh();

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'item_id' => $item->id,
            'quantity' => $item->quantity,
            'line_total' => $item->formattedLineTotal(),
            'line_total_raw' => $item->lineTotalCentavos(),
        ]);
    }

    // ── destroy (DELETE /buyer/cart/{id}) ────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = CartItem::where('id', $id)
            ->where('buyer_id', auth('buyer')->id())
            ->firstOrFail();

        $item->delete();

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'removed_id' => $item->id,
        ]);
    }

    // ── bulkDestroy (DELETE /buyer/cart — removes selected ids) ──────────────

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $buyerId = (int) auth('buyer')->id();
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        // Every requested id must belong to this buyer, otherwise the whole
        // request is rejected — never silently delete only a subset.
        $ownedCount = CartItem::whereIn('id', $ids)->where('buyer_id', $buyerId)->count();

        if ($ownedCount !== count($ids)) {
            abort(403, 'One or more cart items do not belong to you.');
        }

        CartItem::whereIn('id', $ids)->where('buyer_id', $buyerId)->delete();

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'removed_ids' => $ids,
        ]);
    }

    // ── summary (GET /buyer/cart/summary — lightweight JSON for JS refresh) ──

    public function summary(Request $request): JsonResponse
    {
        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request));
    }

    // ── applyVoucher (POST /buyer/cart/voucher) ───────────────────────────────

    public function applyVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            return response()->json(['error' => 'Voucher code not found.'], 422);
        }

        $items = $this->loadItems();
        $selectedIds = $this->selectedIdsFrom($request);
        $subtotal = (new CartSummary($items, null, $selectedIds))->subtotalCentavos;
        $reason = $voucher->rejectionReason($subtotal);

        if ($reason !== null) {
            return response()->json(['error' => $reason], 422);
        }

        session(['cart_voucher' => $code]);
        $this->voucherNotice = null;

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return response()->json(array_merge($summary->toJson(), ['applied' => true]));
    }

    // ── removeVoucher (DELETE /buyer/cart/voucher) ────────────────────────────

    public function removeVoucher(Request $request): JsonResponse
    {
        session()->forget('cart_voucher');
        $this->voucherNotice = null;

        $summary = new CartSummary($this->loadItems(), null, $this->selectedIdsFrom($request));

        return response()->json(array_merge($summary->toJson(), ['removed' => true]));
    }
}
```

## API resources - the wire shape

### web/app\Http\Resources\CategoryResource.php  (37 lines)

```php
<?php

namespace App\Http\Resources;

use App\Models\Shared\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One category, as the app's category chips need it.
 *
 * The app renders a row of chips built from a list of *names* — its own mock
 * list is `['All', 'Fashion', 'Home & Living', 'Beauty']`, four names for the
 * server's fourteen curated rows, and that mismatch is why the filter is sent as
 * a name (`CatalogQuery.category`) rather than as an id: whichever side owns the
 * names must be the side that resolves them.
 *
 * `label` is therefore the field the app reads, and `id` is carried because it is
 * the stable half of the pair — a curated list can be relabelled, and a caller
 * that has an id should not have to re-derive it from display text.
 *
 * `icon` (a lucide icon name) is deliberately absent: the app draws its category
 * tiles from its own artwork, so a shipped icon name would be a string nobody
 * reads.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
        ];
    }
}
```

### web/app\Http\Resources\OrderResource.php  (89 lines)

```php
<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One **seller order**, in the shape the Flutter app's `Order` wants.
 *
 * ## Why a seller order, and not an order
 *
 * A marketplace order can hold goods from several shops, and its `seller_orders`
 * are the per-shop fulfilment units: exactly one seller ships a parcel, and the
 * buyer's history is a list of parcels. That is the split the app already models —
 * `OrderState.placeOrders` groups a cart by seller and the mock mints one order per
 * seller — which is why this resource is keyed on the seller order: one row here is
 * one row there, and the app needs no change of shape.
 *
 * ## The money is centavos, and the status is the server's own vocabulary
 *
 * `subtotal_minor` and each line's `price_minor` are centavos, like every amount
 * the app reads (`money.dart` owns the only conversion). The status is sent as the
 * server's string (`PLACED`, `SORTED`, `OUT_FOR_DELIVERY`, …) rather than being
 * pre-mapped into the app's four display stages: thirteen is not four, and the
 * mapping belongs on the side that *chose* to simplify. Pre-chewing it here would
 * throw away the fact that a parcel is `SORTED` rather than merely in transit, which
 * is the detail a tracker will want the day it starts showing real progress.
 *
 * ## `product_ids` is why this endpoint is worth having
 *
 * The app's joins — "stop showing me what I already bought", the wishlist's IN CART
 * tag, the recently-viewed filter — key on product ids, and an order history that
 * cannot produce them leaves every one of those surfaces unable to reference a
 * purchase. A line whose product row has since been removed keeps its snapshot
 * (`product_name`, `price_minor`) and simply contributes no id, which is the same
 * "contents unknown" rule the app already documents for old orders.
 *
 * @mixin SellerOrder
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $this->orderItems;

        return [
            // A string, for the same reason the catalogue's ids are: the app keys
            // its order list, its tracker and its review joins on string ids that
            // came from the mock ('ORD-10246'), so a live id has to arrive in the
            // same type or every set-membership test needs to know the mode.
            'id' => (string) $this->id,
            // The human-facing number a person reads back to support ("ORD-" +
            // date + six characters). Carried because the app's confirmation and
            // history both print it, and re-deriving it is not possible from an id.
            'reference' => (string) ($this->order?->reference ?? ''),
            // The seller order's own status — it is the parcel's state that moves
            // through the pipeline, not the checkout's.
            'status' => $this->status->value,
            'shop' => $this->seller?->business_name ?? '',
            'subtotal_minor' => (int) $this->subtotal_minor,
            'items' => $items
                ->map(fn (OrderItem $item): array => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'price_minor' => (int) $item->price_minor,
                    // Null for a line whose product was removed: the line survives
                    // as an audit record, and the app renders the snapshot.
                    'product_id' => $item->product_id === null ? null : (string) $item->product_id,
                ])
                ->values()
                ->all(),
            // Only the ids that still resolve, in line order. The app's `productIds`
            // means "the catalogue items this order contains"; a removed product is
            // no longer one of them, and a null in that list would be an id that
            // matches nothing anywhere.
            'product_ids' => $items
                ->pluck('product_id')
                ->filter(fn (?int $id): bool => $id !== null)
                ->map(fn (int $id): string => (string) $id)
                ->values()
                ->all(),
        ];
    }
}
```

### web/app\Http\Resources\ProductResource.php  (130 lines)

```php
<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One catalogue product, in the shape the Flutter app's `Product` wants.
 *
 * ## Money leaves here in centavos
 *
 * `products.price` is a `DECIMAL(10,2)` in **pesos** — the seller types `120.50`
 * into the web form and that is what is stored. The app's money boundary
 * (`lib/data/dto/money.dart`) reads every server amount as **integer centavos**
 * and converts once, on arrival, with the rule that no other file in the app may
 * divide or multiply by 100. So the conversion happens here, on the one side
 * that owns the column, and the field is named `price_minor` so a reader cannot
 * mistake which scale it is in. Sending `120.50` would not fail loudly — it would
 * render as ₱12,050.00.
 *
 * ## Three things the app renders that this resource deliberately omits
 *
 * The app's product page has an "ON SALE" badge (`compareAtPrice`), a star
 * average with a review count, and a "FROM THE MAKER" note. None of the three
 * exists server-side: `products` has no `compare_at_price` column, there is no
 * reviews table, and a maker note has no column of its own. The *web* buyer page
 * fakes all three (a struck-through price computed as `price / 0.75`, a hardcoded
 * 4.9 with "284 reviews", placeholder feature copy), which is exactly what this
 * project's no-invented-content rule forbids in an app. So they are absent here
 * rather than plausible: an absent field renders as no badge and no rating, and
 * the day a column exists it is added in one place.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // A string, always. The app keys its wishlist, its recently-viewed
            // rail and its order joins on `Product.id`, and those ids are string
            // literals in the mock catalogue ('p1', 'p2'), so a live id has to
            // arrive in the same type or every set membership test would need to
            // know which mode it was in.
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => (string) ($this->description ?? ''),
            // The category *label*, not an id: the app's catalogue filter sends a
            // name back (see `CatalogQuery.category`), and the categories table is
            // a fixed curated list whose label is the thing a person reads.
            'category' => $this->category?->label ?? 'Uncategorised',
            'price_minor' => (int) round(((float) $this->price) * 100),
            // Carried because the alternative — hiding an out-of-stock product —
            // is an unexplained absence, and the app has no stock UI to explain
            // it with. It is a fact either way; the app simply does not read it.
            'in_stock' => ! $this->isOutOfStock(),
            'seller' => [
                // `products.seller_id` is NOT NULL with a cascading foreign key,
                // so a product without a seller is not a state this can be in.
                'name' => $this->seller?->business_name ?? '',
            ],
            'images' => $this->imagesCoverFirst(),
        ];
    }

    /**
     * The product's photo URLs, cover first.
     *
     * "Cover first" is the whole of the app's contract for a product's photos
     * (`Product.images`: an ordered list whose first entry is the cover), and it
     * is what makes the gallery and the stacked showcase work — both layouts
     * assume there is more than one photo of the thing.
     *
     * The cover is the image flagged `is_primary`, and the rest follow in
     * `sort_order` — which is the order the seller arranged them in, since the
     * relation itself is ordered that way. A product whose images are all
     * unflagged (possible: the flag is set on upload, not by a constraint) still
     * has a cover, because the first by `sort_order` is used.
     *
     * The images are read through the relation rather than off a loaded
     * collection so that a caller who forgets `with('images')` gets a query
     * instead of a silent empty gallery.
     *
     * @return list<string>
     */
    private function imagesCoverFirst(): array
    {
        /** @var \Illuminate\Support\Collection<int, ProductImage> $images */
        $images = $this->relationLoaded('images')
            ? $this->images
            : $this->images()->get();

        if ($images->isEmpty()) {
            return [];
        }

        $cover = $images->firstWhere('is_primary', true) ?? $images->first();

        return $images
            ->sortBy(fn (ProductImage $image) => $image->is($cover) ? -1 : $image->sort_order)
            ->map(fn (ProductImage $image) => $this->photoUrl($image->path))
            ->values()
            ->all();
    }

    /**
     * A photo's URL, whether the column holds an uploaded path or a whole URL.
     *
     * A seller's upload stores a path (`products/abc.jpg`) and the filesystem
     * turns that into a URL. A seeded or externally hosted photo is *already* a
     * URL, and running one through the disk's URL builder mangles it —
     * `/storage/https://…` — so that case passes through untouched. This is the
     * only branch between the two.
     *
     * Worth knowing while reading this: on the `local` disk `Storage::url()`
     * answers with a *relative* `/storage/…`, which the app's `Image.network`
     * cannot fetch. That is a separate problem from the one solved here (it
     * affects uploaded photos), and it is recorded in `progress.md`.
     */
    private function photoUrl(string $path): string
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : Storage::url($path);
    }
}
```

### web/app\Http\Resources\VoucherResource.php  (50 lines)

```php
<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One voucher from the catalogue the cart's voucher picker shows.
 *
 * ## `value` has two scales, and `type` is what tells them apart
 *
 * `vouchers.value` is centavos for a fixed voucher and a whole percentage for a
 * percent voucher — never a float, and never both. That is the server's own
 * storage rule (see `VoucherSeeder`), and this resource reports it faithfully
 * rather than flattening the two into fields that would have to be null half the
 * time. A client reads `type` first; the app's mapper does, and has a test for
 * each scale.
 *
 * ## What is *not* here, and why it matters more than it looks
 *
 * The app's `Voucher` also carries a `discountRate` and a `maxDiscount` cap, and
 * its pricing engine (`config/pricing.dart`) computes with them. Neither exists
 * server-side: Laravel's vouchers have no cap, no category scope and no shipping
 * rebate. A cap invented here would be a *money* number the server does not agree
 * with, so the honest thing is to send the facts (code, type, value, minimum
 * spend, expiry) and let the app decide what it can express — which it does, in
 * `remote_catalog.dart`, including refusing to offer what it cannot represent
 * correctly. See `docs/api-contract.md` OPEN QUESTIONS §0.1/§0.2 for the pricing
 * decision this defers to.
 *
 * @mixin Voucher
 */
class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            // 'fixed' | 'percent' — the scale of `value` below.
            'type' => $this->type->value,
            'value' => (int) $this->value,
            // Centavos here, like every other money field the app reads, so the
            // one conversion in `money.dart` applies.
            'min_spend_minor' => $this->min_spend,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
```

## Form requests the API validates with

### web/app\Http\Requests\Buyer\StoreBuyerRegistrationRequest.php  (58 lines)

```php
<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuyerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => ['required', 'string', 'email', 'max:255', 'unique:buyers,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today'],

            'address_mode' => ['required', Rule::in(['api', 'manual'])],

            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],

            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province, or switch to manual address entry.',
        ];
    }
}
```

### web/app\Http\Requests\Logistics\StoreLogisticsRegistrationRequest.php  (83 lines)

```php
<?php

namespace App\Http\Requests\Logistics;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLogisticsRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:logistics_providers,email',
                function ($attribute, $value, $fail) {
                    if (Buyer::where('email', $value)->exists()) {
                        $fail('This email is already registered as a buyer. Use a different email.');

                        return;
                    }

                    if (Seller::where('email', $value)->exists()) {
                        $fail('This email is already registered as a seller. Use a different email.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today'],

            'business_name' => ['required', 'string', 'max:255'],

            'address_mode' => ['required', Rule::in(['api', 'manual'])],

            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],

            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'dti_permit_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'dti_permit_upload.mimes' => 'DTI permit must be a JPG, PNG, or PDF.',
            'dti_permit_upload.max' => 'DTI permit must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province, or switch to manual address entry.',
            'business_name.required' => 'Enter your business or company name.',
        ];
    }
}
```

## Models the API touches

### web/app\Models\Product.php  (103 lines)

```php
<?php

namespace App\Models;

use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'seller_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock_quantity',
        'low_stock_threshold',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeActiveInStock(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('stock_quantity', '>', 0);
    }

    public function formattedPrice(): string
    {
        return '₱'.number_format((float) $this->price, 2);
    }

    public function catalogImageUrl(): ?string
    {
        $image = $this->images->firstWhere('is_primary', true) ?? $this->images->first();

        return $image !== null ? Storage::url($image->path) : null;
    }

    public static function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        return $slug;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->low_stock_threshold && $this->stock_quantity > 0;
    }
}
```

### web/app\Models\ProductImage.php  (29 lines)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'path',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

### web/app\Models\CartItem.php  (74 lines)

```php
<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['buyer_id', 'product_id', 'quantity'];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalCentavos(): int
    {
        return (int) bcmul(
            bcmul((string) $this->product->price, '100', 0),
            (string) $this->quantity,
            0,
        );
    }

    /**
     * Formatted line total (₱X,XXX.XX).
     */
    public function formattedLineTotal(): string
    {
        return '₱'.number_format($this->lineTotalCentavos() / 100, 2);
    }

    /**
     * True when the linked product is purchaseable (active, not soft-deleted,
     * stock > 0). Used by the cart page to dim unavailable rows.
     */
    public function isAvailable(): bool
    {
        $p = $this->product;

        return $p !== null
            && ! $p->trashed()
            && $p->status === 'active'
            && $p->stock_quantity > 0;
    }
}
```

### web/app\Models\Order.php  (92 lines)

```php
<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'buyer_id',
        'reference',
        'shipping_address',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    // ── Reference generation ─────────────────────────────────────────────────

    /**
     * Human-facing order number, e.g. ORD-20260922-4F8K2Q. The unique index on
     * orders.reference is the final guard; this loop avoids the (rare) collision
     * up front.
     */
    public static function generateUniqueReference(): string
    {
        do {
            $reference = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * A buyer is a row in the buyers table (orders.buyer_id → buyers.id).
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    /**
     * Per-seller fulfilment units created from this checkout.
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    // ── Formatting helpers (₱X,XXX.XX from integer centavos) ─────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }

    public function formattedDiscount(): string
    {
        return '-₱'.number_format($this->discount_minor / 100, 2);
    }

    public function formattedTotal(): string
    {
        return '₱'.number_format($this->total_minor / 100, 2);
    }
}
```

### web/app\Models\OrderItem.php  (68 lines)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'product_id',
        'product_name',
        'quantity',
        'price_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_minor' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Items hang off seller_orders, never directly off orders.
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /**
     * The product that was purchased (nullable FK — the line survives as an
     * audit record even if the product is removed later).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalMinor(): int
    {
        return $this->quantity * $this->price_minor;
    }

    public function formattedLineTotal(): string
    {
        return '₱'.number_format($this->lineTotalMinor() / 100, 2);
    }

    public function formattedUnitPrice(): string
    {
        return '₱'.number_format($this->price_minor / 100, 2);
    }
}
```

### web/app\Models\SellerOrder.php  (73 lines)

```php
<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'order_id',
        'seller_id',
        'subtotal_minor',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The checkout-level order this fulfilment unit belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The shop fulfilling this slice of the order.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Payment is per seller_order, not per order.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }
}
```

### web/app\Models\Payment.php  (64 lines)

```php
<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — use markPaid() so the
     * payment state and paid_at stay consistent. Transitions arrive in a
     * later phase (COD collection on delivery).
     */
    protected $fillable = [
        'seller_order_id',
        'amount_minor',
        'method',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Payment is per seller_order, not per order.
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    // ── Controlled transitions ───────────────────────────────────────────────

    public function markPaid(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ])->save();
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedAmount(): string
    {
        return '₱'.number_format($this->amount_minor / 100, 2);
    }
}
```

### web/app\Models\InventoryTransaction.php  (47 lines)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger (project rule 7). Rows are written inside the same
 * transaction that moved the stock and are never updated or deleted.
 */
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'seller_order_id',
        'quantity_before',
        'quantity_after',
        'delta',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'delta' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
```

### web/app\Models\Voucher.php  (128 lines)

```php
<?php

namespace App\Models;

use App\Enums\VoucherType;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_spend',
        'is_active',
        'expires_at',
        'usage_limit',
        'times_used',
    ];

    protected $casts = [
        'type' => VoucherType::class,
        'value' => 'integer',
        'min_spend' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'usage_limit' => 'integer',
        'times_used' => 'integer',
    ];

    protected static function newFactory(): VoucherFactory
    {
        return VoucherFactory::new();
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Resolve a voucher code (as stored in the session) against a subtotal.
     *
     * The single place where a code is turned into a usable Voucher, so the
     * cart page, the cart JSON endpoints and checkout all validate identically.
     * A rejection reason is written to $notice when the voucher cannot be used.
     *
     * @param  string|null  $code  Raw code, case-insensitive
     * @param  int  $subtotalCentavos  Subtotal the voucher must be valid against
     * @param  string|null  $notice  Receives the rejection reason, if any
     */
    public static function resolveCode(?string $code, int $subtotalCentavos, ?string &$notice = null): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $voucher = static::where('code', strtoupper(trim($code)))->first();

        $reason = $voucher === null
            ? 'This voucher code is no longer valid.'
            : $voucher->rejectionReason($subtotalCentavos);

        if ($reason !== null) {
            $notice = $reason;

            return null;
        }

        return $voucher;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calculate the discount in centavos for a given subtotal (also centavos).
     * Never returns more than the subtotal.
     */
    public function discountCentavos(int $subtotalCentavos): int
    {
        $discount = match ($this->type) {
            VoucherType::Fixed => $this->value,                        // already centavos
            VoucherType::Percent => (int) intdiv($subtotalCentavos * $this->value, 100),
            default => 0,
        };

        return min($discount, $subtotalCentavos);
    }

    /**
     * Validate the voucher against a subtotal and return a plain-English
     * rejection reason, or null if the voucher is valid.
     */
    public function rejectionReason(int $subtotalCentavos): ?string
    {
        if (! $this->is_active) {
            return 'This voucher is no longer active.';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'This voucher has expired.';
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            return 'This voucher has reached its usage limit.';
        }

        if ($this->min_spend !== null && $subtotalCentavos < $this->min_spend) {
            $formatted = '₱'.number_format($this->min_spend / 100, 2);

            return "A minimum spend of {$formatted} is required to use this voucher.";
        }

        return null;
    }

    /** Human-readable discount label, e.g. "-₱200.00" or "-10% (-₱56.96)". */
    public function discountLabel(int $subtotalCentavos): string
    {
        if ($this->type === VoucherType::Percent) {
            return "-{$this->value}% (-₱".number_format($this->discountCentavos($subtotalCentavos) / 100, 2).')';
        }

        return '-₱'.number_format($this->discountCentavos($subtotalCentavos) / 100, 2);
    }
}
```

### web/app\Models\Buyer\Buyer.php  (213 lines)

```php
<?php

namespace App\Models\Buyer;

use App\Models\CartItem;
use Carbon\Carbon;
use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Buyer extends Authenticatable
{
    // HasApiTokens is what lets a buyer hold a bearer token: the mobile app has
    // no session cookie and no CSRF token, so a token is the only credential it
    // can present. Added to this model rather than to all four at once — the API
    // signs buyers in so far, and a trait on a model nothing authenticates is an
    // untested claim.
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'password',
        'contact_no',
        'birthday',
        'age',
        'address_mode',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'street',
        'house_number',
        'address_detail',
        'upload_id_path',
        'email_verification_code',
        'email_verification_expires_at',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    protected static function newFactory(): BuyerFactory
    {
        return BuyerFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isVerificationLinkExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    /**
     * Marks the email verified and moves the buyer into the admin queue.
     * Called by VerifyBuyerEmailController once the token checks out.
     */
    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => 'pending_approval',
        ])->save();
    }

    /**
     * Whether the email gate is behind this account.
     *
     * The web OTP controller and the API's sign-in gate both ask this, and they
     * must agree: if one of them considered the gate open while the other did
     * not, the app would offer a confirmation the server treats as finished (or
     * refuse one it is waiting for). `status` is the source of truth because it
     * is what `markEmailVerified()` moves — `email_verified_at` is set at the
     * same moment but a row can predate the column.
     *
     * `rejected` is *not* past verification: a rejected account never confirmed
     * its email, and reporting otherwise would tell someone their application
     * was accepted.
     */
    public function isPastEmailVerification(): bool
    {
        return in_array($this->status, ['pending_approval', 'approved'], true);
    }

    /**
     * Generates a fresh 6-digit code, stores its hash, and returns the raw code
     * for the caller to email.
     *
     * All three steps or none: the row keeps only the hash, so a caller that
     * generated a code without storing it would email something the server can
     * never match. This lives on the model because the hash algorithm and the
     * 10-minute window are one policy — spelled separately in the registration
     * and resend paths it would be two chances to drift.
     */
    public function issueEmailVerificationCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'email_verification_code' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    /**
     * Whether `$code` is the code this row is waiting for.
     *
     * `hash_equals` rather than `===`, so a wrong code cannot be found one byte
     * at a time by timing the comparison. A null column never matches anything,
     * which is what makes an already-verified row (code cleared) reject every
     * attempt instead of accepting `hash('sha256', '')`.
     */
    public function verifyEmailCode(string $code): bool
    {
        if ($this->email_verification_code === null) {
            return false;
        }

        return hash_equals($this->email_verification_code, hash('sha256', $code));
    }

    /**
     * Creates a buyer row from a validated registration payload.
     *
     * The single spelling of "what a new buyer row contains", because there are
     * now two doors into it (the web modal and the mobile app) and they must
     * produce the same account. `age` is computed here rather than accepted from
     * the client, the address codes stay nullable for manual addresses, and the
     * row starts at `pending_verification` — the gate the code below it exists
     * to open.
     *
     * The verification code is *not* issued here: `issueEmailVerificationCode()`
     * does that, so both doors have exactly one code path.
     */
    public static function createFromRegistration(array $data, string $uploadIdPath): self
    {
        return static::create([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'sex' => $data['sex'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'contact_no' => $data['contact_no'],
            'birthday' => $data['birthday'],
            'age' => Carbon::parse($data['birthday'])->age,
            'address_mode' => $data['address_mode'],
            'province_code' => $data['province'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'municipality_code' => $data['municipality'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_code' => $data['barangay'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'] ?? null,
            'house_number' => $data['house_number'] ?? null,
            'address_detail' => $data['address_detail'] ?? null,
            'upload_id_path' => $uploadIdPath,
            'status' => 'pending_verification',
        ]);
    }
}
```

### web/app\Models\Seller\Seller.php  (137 lines)

```php
<?php

namespace App\Models\Seller;

use App\Models\Product;
use App\Models\Shared\Category;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Seller extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'password',
        'contact_no',
        'birthday',
        'age',
        'address_mode',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'street',
        'house_number',
        'address_detail',
        'business_name',
        'line_of_business_id',
        'upload_id_path',
        'business_permit_path',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): SellerFactory
    {
        return SellerFactory::new();
    }

    public function lineOfBusiness()
    {
        return $this->belongsTo(Category::class, 'line_of_business_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function storeInitials(): string
    {
        $letters = collect(preg_split('/\s+/', trim($this->business_name)) ?: [])
            ->filter()
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(2)
            ->implode('');

        return $letters !== '' ? $letters : 'S';
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isOtpExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    public function issueOtp(): string
    {
        $otp = (string) random_int(100000, 999999);

        $this->forceFill([
            'email_verification_code' => hash('sha256', $otp),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $otp;
    }

    public function otpMatches(string $submitted): bool
    {
        return $this->email_verification_code !== null
            && hash_equals($this->email_verification_code, hash('sha256', $submitted));
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => 'pending_approval',
        ])->save();
    }
}
```

### web/app\Models\Shared\Category.php  (10 lines)

```php
<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['icon', 'label'];
}
```

### web/app\Models\Shared\User.php  (32 lines)

```php
<?php

namespace App\Models\Shared;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

### web/app\Models\Logistics\LogisticsProvider.php  (120 lines)

```php
<?php

namespace App\Models\Logistics;

use App\Enums\LogisticsProviderStatus;
use Database\Factories\LogisticsProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class LogisticsProvider extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'password',
        'contact_no',
        'birthday',
        'age',
        'business_name',
        'address_mode',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'street',
        'house_number',
        'address_detail',
        'upload_id_path',
        'dti_permit_path',
        'status',
        // Set only by the admin approval/rejection actions (never from request
        // data — RegistrationController writes these explicitly). Without them in
        // the allow-list Model::update() silently drops the decision metadata.
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'status' => LogisticsProviderStatus::class,
        ];
    }

    protected static function newFactory(): LogisticsProviderFactory
    {
        return LogisticsProviderFactory::new();
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === LogisticsProviderStatus::Approved;
    }

    public function isOtpExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    public function issueOtp(): string
    {
        $otp = (string) random_int(100000, 999999);

        $this->forceFill([
            'email_verification_code' => hash('sha256', $otp),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $otp;
    }

    public function otpMatches(string $submitted): bool
    {
        return $this->email_verification_code !== null
            && hash_equals($this->email_verification_code, hash('sha256', $submitted));
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => LogisticsProviderStatus::PendingApproval,
        ])->save();
    }
}
```

## Enums

### web/app\Enums\LogisticsProviderStatus.php  (38 lines)

```php
<?php

namespace App\Enums;

/**
 * Logistics provider application lifecycle.
 *
 * Stored as VARCHAR in `logistics_providers.status` and cast by the model, so
 * the permitted values live in application code rather than in a native MySQL
 * ENUM (project rule #6).
 */
enum LogisticsProviderStatus: string
{
    /** Just registered, the emailed OTP has not been confirmed yet. */
    case PendingVerification = 'pending_verification';

    /** Email confirmed, waiting on an administrator's decision. */
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending email verification',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
```

### web/app\Enums\OrderStatus.php  (40 lines)

```php
<?php

namespace App\Enums;

/**
 * The order-fulfillment pipeline status. Shared by `orders`, `seller_orders`,
 * and `delivery_events` (the append-only shipment log).
 *
 * Stored as a native MySQL ENUM column per the Milestone 4 schema spec — a
 * deliberate deviation from the project's usual VARCHAR-only status rule,
 * with the permitted values mirrored here in application code.
 */
enum OrderStatus: string
{
    case Placed = 'PLACED';

    case Confirmed = 'CONFIRMED';

    case Preparing = 'PREPARING';

    case ReadyForPickup = 'READY_FOR_PICKUP';

    case PickedUp = 'PICKED_UP';

    case AtSortingCenter = 'AT_SORTING_CENTER';

    case Sorted = 'SORTED';

    case AssignedToRider = 'ASSIGNED_TO_RIDER';

    case OutForDelivery = 'OUT_FOR_DELIVERY';

    case Delivered = 'DELIVERED';

    case Completed = 'COMPLETED';

    case DeliveryFailed = 'DELIVERY_FAILED';

    case Returned = 'RETURNED';
}
```

### web/app\Enums\PaymentMethod.php  (12 lines)

```php
<?php

namespace App\Enums;

/**
 * Payment methods for the `payments.method` column. COD is the only method
 * for now; new methods extend the migration enum and this enum together.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
}
```

### web/app\Enums\PaymentStatus.php  (14 lines)

```php
<?php

namespace App\Enums;

/**
 * Payment lifecycle status for the `payments.status` column
 * (VARCHAR + this enum cast — MySQL ENUM is deprecated project-wide).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';

    case Paid = 'paid';
}
```

### web/app\Enums\VoucherType.php  (27 lines)

```php
<?php

namespace App\Enums;

/**
 * Voucher discount kinds.
 *
 * Stored as VARCHAR in `vouchers.type` and cast by the Voucher model, so the
 * permitted values live in application code rather than in a native MySQL ENUM
 * (project rule #6).
 */
enum VoucherType: string
{
    /** A flat amount off, stored in centavos. */
    case Fixed = 'fixed';

    /** A percentage off the subtotal, 0-100. */
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => 'Percentage',
        };
    }
}
```

## Migrations

### web/database\migrations\0001_01_01_000000_create_users_table.php  (49 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
```

### web/database\migrations\0001_01_01_000001_create_cache_table.php  (35 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
```

### web/database\migrations\0001_01_01_000002_create_jobs_table.php  (59 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
```

### web/database\migrations\2024_01_01_000000_create_admins_table.php  (33 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('admin')->comment('admin, super_admin, moderator, support');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
```

### web/database\migrations\2025_09_05_000000_create_buyers_table.php  (75 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTE: a `buyers` table already exists in the DB with a different,
     * partial schema (no `password`, no `age`, different status enum) and
     * was NOT created by any tracked migration. Before running this file,
     * drop the stray table — see SETUP.md for the exact steps.
     */
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->id();

            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->enum('sex', ['male', 'female']);

            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_no', 11);
            $table->date('birthday');
            $table->unsignedTinyInteger('age');

            // Address: one logical address, two possible entry modes.
            $table->enum('address_mode', ['api', 'manual'])->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();

            // Stored on the private 'local' disk, e.g. ids/xxxx.pdf
            $table->string('upload_id_path');

            // Email verification (happens before admin ever sees the row).
            // email_verification_code stores a SHA-256 hash of the emailed
            // token, never the raw token.
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->enum('status', [
                'pending_verification', // just registered, email not confirmed
                'pending_approval',     // email confirmed, waiting on admin
                'approved',
                'rejected',
            ])->default('pending_verification');

            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
```

### web/database\migrations\2025_09_05_100000_create_categories_table.php  (48 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('icon');   // lucide icon name, e.g. 'book-open'
            $table->string('label');  // display name, e.g. 'Books and Media'
            $table->timestamps();
        });

        // Fixed, curated list — not admin/seller editable. If you ever need
        // to add a category, add a row here and re-migrate, don't build a
        // CRUD for this.
        $now = now();
        DB::table('categories')->insert(array_map(fn ($row) => $row + [
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['icon' => 'book-open', 'label' => 'Books and Media'],
            ['icon' => 'heart-pulse', 'label' => 'Health and Beauty'],
            ['icon' => 'mountain', 'label' => 'Sports and Outdoors'],
            ['icon' => 'flower-2', 'label' => 'Home and Garden'],
            ['icon' => 'baby', 'label' => 'Kids and Baby'],
            ['icon' => 'shirt', 'label' => "Men's Apparel"],
            ['icon' => 'sparkles', 'label' => "Women's Apparel"],
            ['icon' => 'smartphone', 'label' => 'Electronics and Gadgets'],
            ['icon' => 'paw-print', 'label' => 'Pet Supplies'],
            ['icon' => 'utensils', 'label' => 'Food and Gourmet'],
            ['icon' => 'car', 'label' => 'Automotive & Motorcycle'],
            ['icon' => 'sofa', 'label' => 'Furniture and Office Equipment'],
            ['icon' => 'gem', 'label' => 'Jewelry and Watches'],
            ['icon' => 'pencil-ruler', 'label' => 'Office and School Supplies'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
```

### web/database\migrations\2025_09_05_100001_create_sellers_table.php  (75 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->enum('sex', ['male', 'female']);

            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_no', 11);
            $table->date('birthday');
            $table->unsignedTinyInteger('age');

            // Address: identical pattern to buyers — one logical address,
            // two possible entry modes.
            $table->enum('address_mode', ['api', 'manual'])->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();

            $table->string('business_name');
            $table->foreignId('line_of_business_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            // Two private-disk uploads, stored separately from buyer IDs.
            $table->string('upload_id_path');
            $table->string('business_permit_path');

            // Email verification (same OTP pattern as buyers).
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->enum('status', [
                'pending_verification', // just registered, email not confirmed
                'pending_approval',     // email confirmed, waiting on admin
                'approved',
                'rejected',
            ])->default('pending_verification');

            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
```

### web/database\migrations\2026_09_12_000001_create_products_table.php  (36 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);

            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

### web/database\migrations\2026_09_12_000002_create_product_images_table.php  (25 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
```

### web/database\migrations\2026_09_20_000001_create_cart_items_table.php  (31 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')
                ->constrained('buyers')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            // One line per product per buyer
            $table->unique(['buyer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
```

### web/database\migrations\2026_09_20_000002_create_vouchers_table.php  (34 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            // VARCHAR, never a native MySQL ENUM (project rule #6). Values are
            // constrained in application code by App\Enums\VoucherType and
            // cast on the model.
            $table->string('type');
            // Stored in centavos for fixed type; as a percent integer (0-100) for percent type
            $table->unsignedBigInteger('value');
            // Minimum order subtotal in centavos before this voucher can be used; null = no minimum
            $table->unsignedBigInteger('min_spend')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
```

### web/database\migrations\2026_09_21_000001_create_logistics_providers_table.php  (75 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logistics provider applications/accounts.
     *
     * `status` is a VARCHAR, never a native MySQL ENUM — the permitted values
     * live in App\Enums\LogisticsProviderStatus and are cast by the model
     * (project rule #6).
     */
    public function up(): void
    {
        Schema::create('logistics_providers', function (Blueprint $table) {
            $table->id();

            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->string('sex', 10);

            // Unique inside this table only; cross-role reuse (buyer/seller) is
            // rejected in StoreLogisticsRegistrationRequest, not by the schema.
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_no', 11);
            $table->date('birthday');
            $table->unsignedTinyInteger('age');

            $table->string('business_name');

            // Address: identical pattern to buyers/sellers — one logical address,
            // two possible entry modes.
            $table->string('address_mode', 10)->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();

            // Two private-disk uploads, stored separately from buyer/seller files.
            $table->string('upload_id_path');
            $table->string('dti_permit_path');

            // Email verification (same OTP pattern as sellers; the stored code is
            // a SHA-256 hash, never the raw one-time code).
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->string('status')->default('pending_approval');

            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_providers');
    }
};
```

### web/database\migrations\2026_09_22_085043_change_products_status_to_string.php  (28 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('string', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('string', function (Blueprint $table) {
            //
        });
    }
};
```

### web/database\migrations\2026_09_22_110001_create_orders_table.php  (46 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            // buyer_id → buyers.id: RESTRICT — orders are permanent records;
            // a buyer with order history must never be deleted out from under them.
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();

            // Human-facing order number, e.g. ORD-20260922-4F8K2Q.
            $table->string('reference')->unique();

            // Immutable snapshot of the buyer's address at checkout time —
            // a JSON copy, deliberately NOT a live FK, so later address edits
            // can never rewrite historical orders.
            $table->json('shipping_address');

            // Money as unsigned integer centavos (₱1,234.56 = 123456).
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('discount_minor')->default(0);
            $table->unsignedInteger('total_minor');

            // VARCHAR + App\Enums\OrderStatus cast (MySQL ENUM is deprecated
            // project-wide for business statuses). Starts at PLACED.
            $table->string('status', 20)->default('PLACED');

            $table->timestamps();

            // Buyer order-history listings read "my orders, newest first".
            $table->index(['buyer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

### web/database\migrations\2026_09_22_110002_create_seller_orders_table.php  (41 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->id();

            // order_id → orders.id: RESTRICT — permanent record; a seller_order
            // is part of the buyer's transaction history.
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            // seller_id → sellers.id: RESTRICT — permanent record; a seller with
            // transaction history must not be deletable.
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();

            // This shop's slice of the order, in unsigned integer centavos
            // (before the order-level voucher discount, which lives on orders).
            $table->unsignedInteger('subtotal_minor');

            // VARCHAR + App\Enums\OrderStatus cast. Starts at PLACED; the seller
            // fulfilment workflow (CONFIRMED → … → READY_FOR_PICKUP) is Phase 3.
            $table->string('status', 20)->default('PLACED');

            $table->timestamps();

            // Exactly one seller_order per shop per order.
            $table->unique(['order_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_orders');
    }
};
```

### web/database\migrations\2026_09_22_110003_create_order_items_table.php  (37 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            // seller_order_id → seller_orders.id: RESTRICT — permanent record;
            // items belong to their seller_order, never directly to orders.
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();

            // product_id → products.id: SET NULL — keep the line as an audit
            // record even if the product row is later removed (products are
            // soft-deleted in practice, so this rarely fires).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // Snapshots at order time: the product may change or vanish later,
            // but the line must always show what was actually bought.
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price_minor');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
```

### web/database\migrations\2026_09_22_110004_create_payments_table.php  (42 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // seller_order_id → seller_orders.id: RESTRICT — payments are
            // permanent financial records (project rule: never deleted), so a
            // seller_order with a payment must not be removable.
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();

            // Amount to collect for this shop's parcel, in unsigned integer centavos.
            $table->unsignedInteger('amount_minor');

            // COD is the only method for now; new methods extend this later.
            $table->string('method', 20)->default('cod');

            // pending | paid — App\Enums\PaymentStatus cast on the model.
            $table->string('status', 20)->default('pending');

            // Set when the COD parcel is handed over and paid (later pass).
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // One payment per seller_order (enforces the hasOne).
            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
```

### web/database\migrations\2026_09_22_110005_create_inventory_transactions_table.php  (45 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only stock ledger (project rule 7): every READ → VALIDATE →
        // MODIFY STOCK operation writes one row inside the same transaction
        // that moved the stock.
        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();

            // product_id → products.id: RESTRICT — the ledger is history; it
            // must never be orphaned or silently destroyed with the product.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // seller_order_id → seller_orders.id: RESTRICT — permanent link to
            // the order that consumed the stock. Nullable so non-order
            // adjustments (restocks, corrections) can also be recorded later.
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->restrictOnDelete();

            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            // Signed: negative for consumption, positive for restocks.
            $table->integer('delta');

            // Why the stock moved, e.g. 'checkout' (later: 'restock', 'cancel').
            $table->string('reason', 30);

            $table->timestamps();

            // Stock-history reads for a product, newest first.
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
```

### web/database\migrations\2026_09_30_144946_create_personal_access_tokens_table.php  (33 lines)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
```

## Seeders - the demo data the app is developed against

### web/database\seeders\DatabaseSeeder.php  (45 lines)

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // No test user is created here, and that is a fix rather than an
        // omission: Laravel's default scaffolding created an `App\Models\User`,
        // a class this application has never had — it has Buyer, Seller and
        // Admin — so `php artisan db:seed` died with "Class App\Models\User not
        // found" before it reached either catalogue below. Inventing a User model
        // to satisfy a seeder would be building a model nobody authenticates.
        //
        // What is still owed is an admin row to sign in with, because the
        // approval screens are real. That is not seeded here: a demo credential
        // is a decision, not a fixture.

        // The catalogues a browsable dev database needs, the voucher list the cart
        // offers, and the one buyer account a live build can sign in with. All are
        // re-runnable, which matters because this is the command a person repeats
        // after changing a price.
        //
        // The demo orders come last because they need a buyer and stock to place
        // against — and they are placed *through* `OrderPlacer`, so the rows they
        // write are the rows a real checkout writes, stock ledger and all.
        $this->call([
            VoucherSeeder::class,
            DemoCatalogueSeeder::class,
            DemoBuyerSeeder::class,
            DemoOrderSeeder::class,
        ]);
    }
}
```

### web/database\seeders\DemoBuyerSeeder.php  (94 lines)

```php
<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use Illuminate\Database\Seeder;

/**
 * One buyer who can sign in to a live build.
 *
 * ## Why a credential is seeded, and why that needs the paragraph
 *
 * Everything else in the demo data is content — shops, goods, prices, photos.
 * This is a *login*, and a login is the one fixture that is also a way in. It
 * earns its place because without it nothing else is reachable: a registration
 * made through the app lands at `pending_approval` with no admin to approve it,
 * so a live build had no account that could reach the shell at all, and every
 * screen behind it — the catalogue especially — could only be verified over HTTP.
 *
 * Three rules keep it a dev affordance rather than a back door:
 *
 *  * **It refuses to run in production.** Not "is not run": refuses, with a
 *    warning, before writing anything. The app has the same shape of guard for
 *    its test-only controls (`kShowDevTools`), for the same reason — a comment
 *    saying "dev only" protects nothing at runtime.
 *  * **The password is `password`**, the value every factory and test account in
 *    this project already uses, so it reads as a fixture rather than as something
 *    plausible.
 *  * **It is idempotent**, keyed on the email, so re-seeding neither duplicates
 *    the account nor resets a password changed while testing.
 */
class DemoBuyerSeeder extends Seeder
{
    /** The one thing in this project that is both a fixture and a way in. */
    public const EMAIL = 'buyer@zefanya.test';

    /** Documented above: the same password every factory here uses. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoBuyerSeeder: skipped — a demo credential must never exist in production.'
            );

            return;
        }

        // `firstOrNew`, with the password written only for a row that does not
        // exist yet: re-seeding has to create the account when it is missing
        // without resetting one somebody has been using.
        $buyer = Buyer::firstOrNew(['email' => self::EMAIL]);

        $buyer->fill([
            'last_name' => 'Santos',
            'first_name' => 'Marites',
            'sex' => 'female',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'age' => 31,
            'address_mode' => 'manual',
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Pinyahan',
            'street' => 'Maginhawa Street',
            'house_number' => '42',
            'upload_id_path' => 'buyers/demo/buyer-id.jpg',
            // Approved — and verified on the record below. The API's sign-in
            // gate answers for both, and an account that is one but not the
            // other is a state no real flow produces.
            'status' => 'approved',
        ]);

        if (! $buyer->exists) {
            $buyer->password = self::PASSWORD;
        }

        $buyer->save();

        // Neither timestamp is fillable — the registration flow writes them with
        // `forceFill` too — so they are written the same way here, and kept if
        // they already exist, so re-seeding never rewrites when an account was
        // verified.
        $buyer->forceFill([
            'email_verified_at' => $buyer->email_verified_at ?? now(),
            'approved_at' => $buyer->approved_at ?? now(),
        ])->save();

        $this->command?->info(
            'Demo buyer: '.self::EMAIL.' / '.self::PASSWORD.' (approved, so a live build can sign in).'
        );
    }
}
```

### web/database\seeders\DemoCatalogueSeeder.php  (335 lines)

```php
<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A browsable demo catalogue, so a live build has something to show.
 *
 * ## Why this exists
 *
 * The catalogue endpoints are real and the app can read them, but the dev
 * database held **0 sellers and 0 products**: live mode was correct and empty,
 * which is indistinguishable from broken. This fills it with shops and goods that
 * the app can actually render, and nothing else — no orders, no reviews, no
 * counters. Nothing here claims anything the server does not know (see the
 * project's no-invented-content rule): names, prices, stock and photos are the
 * only facts, because they are the only columns.
 *
 * ## Re-runnable, deliberately
 *
 * Sellers are keyed by email and products by slug, so seeding twice updates
 * rather than duplicates — which matters because this is a *dev* tool a person
 * will run again after changing a price or adding a product.
 *
 * ## The photos are the one thing that is not real
 *
 * `product_images.path` holds a Lorem Picsum URL, which is the same
 * placeholder-image service the app's own fallback already uses
 * (`Product.photos` → `placeholderPhoto()`), so a live catalogue looks like the
 * mock one instead of an empty gallery. A real photo arrives by going through the
 * seller's own upload flow (`routes/seller.php`), which stores a path; the
 * resource distinguishes the two by asking whether the value is already a URL.
 *
 * ## Three products are seeded that must NOT be visible
 *
 * A draft product, an inactive one, and a product belonging to a seller who is
 * still `pending_approval`. They are here because the visibility rule is the one
 * thing in the catalogue with a person on the other side, and a demo database
 * that only contains happy rows cannot tell you the rule is working. The test
 * that accompanies this seeder asserts the list returns the active twelve.
 */
class DemoCatalogueSeeder extends Seeder
{
    /**
     * The demo shops. Every one is approved and email-verified, because an
     * unapproved seller's products are invisible by rule — a demo catalogue made
     * of invisible products would look exactly like an empty one.
     *
     * @var list<array<string, mixed>>
     */
    private const SELLERS = [
        [
            'business_name' => 'Loom & Larder',
            'category' => 'Home and Garden',
            'email' => 'loom-and-larder@zefanya.test',
            'first_name' => 'Marisol',
            'last_name' => 'Aguirre',
            'sex' => 'female',
            'province' => 'Cebu',
            'municipality' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Sanciangko Street',
            'house_number' => '12-A',

        ],
        [
            'business_name' => 'Tahanan Ceramics',
            'category' => 'Home and Garden',
            'email' => 'tahanan-ceramics@zefanya.test',
            'first_name' => 'Elias',
            'last_name' => 'Monteverde',
            'sex' => 'male',
            'province' => 'Iloilo',
            'municipality' => 'Iloilo City',
            'barangay' => 'Molo',
            'street' => 'Avanceña Street',
            'house_number' => '7',

        ],
        [
            'business_name' => 'Hiraya Weaves',
            'category' => "Women's Apparel",
            'email' => 'hiraya-weaves@zefanya.test',
            'first_name' => 'Dalisay',
            'last_name' => 'Bautista',
            'sex' => 'female',
            'province' => 'Abra',
            'municipality' => 'Bangued',
            'barangay' => 'Calaba',
            'street' => 'Magallanes Street',
            'house_number' => '45',

        ],
        [
            'business_name' => 'Kape ni Lolo',
            'category' => 'Food and Gourmet',
            'email' => 'kape-ni-lolo@zefanya.test',
            'first_name' => 'Ruben',
            'last_name' => 'Dumaguing',
            'sex' => 'male',
            'province' => 'Benguet',
            'municipality' => 'La Trinidad',
            'barangay' => 'Pico',
            'street' => 'Km 5 Halsema Highway',
            'house_number' => '3',

        ],
        [
            'business_name' => 'Alon Botanicals',
            'category' => 'Health and Beauty',
            'email' => 'alon-botanicals@zefanya.test',
            'first_name' => 'Teresa',
            'last_name' => 'Villamor',
            'sex' => 'female',
            'province' => 'Bohol',
            'municipality' => 'Dauis',
            'barangay' => 'Biking',
            'street' => 'Purok 4',
            'house_number' => '21',

        ],
        // Not approved: exists so the visibility rule has something to hide.
        [
            'business_name' => 'Bagong Uling Supplies',
            'category' => 'Furniture and Office Equipment',
            'email' => 'bagong-uling@zefanya.test',
            'first_name' => 'Nestor',
            'last_name' => 'Palma',
            'sex' => 'male',
            'province' => 'Quezon',
            'municipality' => 'Lucban',
            'barangay' => 'Poblacion',
            'street' => 'Quezon Avenue',
            'house_number' => '88',

            'status' => 'pending_approval',
        ],
    ];

    /**
     * How many products `GET /api/products` must return once this seeder has run.
     *
     * Thirteen of the fifteen rows below: one is a draft and one belongs to a
     * seller who is still waiting on admin approval. `DemoCatalogueSeederTest`
     * asserts against this constant, so the number a reader sees here and the
     * number the app is promised cannot drift apart.
     */
    public const VISIBLE_PRODUCTS = 13;

    /**
     * The demo goods.
     *
     * `photos` is how many placeholder photos to attach — the gallery and the
     * stacked showcase both need more than one photo to exist at all, so most
     * rows get two to four and a live build exercises them.
     *
     * @var list<array<string, mixed>>
     */
    private const PRODUCTS = [
        ['shop' => 'Loom & Larder', 'category' => 'Home and Garden', 'name' => 'Handwoven Rattan Tote', 'price' => 1250.75, 'stock' => 18, 'photos' => 4,
            'description' => 'Handwoven in Cebu from province-grown rattan; flat woven base, leather handle.'],
        ['shop' => 'Loom & Larder', 'category' => 'Home and Garden', 'name' => 'Capiz Shell Wind Chime', 'price' => 680.00, 'stock' => 0, 'photos' => 3,
            'description' => 'Capiz shells on waxed cotton, in four graduated tiers.'],
        ['shop' => 'Loom & Larder', 'category' => 'Furniture and Office Equipment', 'name' => 'Kamagong Stool', 'price' => 3800.00, 'stock' => 3, 'photos' => 3,
            'description' => 'Solid kamagong with mortise-and-tenon joinery, finished in beeswax and linseed oil.'],
        ['shop' => 'Tahanan Ceramics', 'category' => 'Home and Garden', 'name' => 'Terracotta Planter Trio', 'price' => 980.50, 'stock' => 7, 'photos' => 3,
            'description' => 'Three unglazed planters thrown by hand, with drain holes and saucers.'],
        ['shop' => 'Tahanan Ceramics', 'category' => 'Home and Garden', 'name' => 'Stoneware Rice Bowl Set', 'price' => 1450.00, 'stock' => 12, 'photos' => 4,
            'description' => 'Four stoneware bowls in a wood-ash glaze, each a slightly different depth.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Inabel Wrap Skirt', 'price' => 1650.00, 'stock' => 9, 'photos' => 3,
            'description' => 'Woven on a wooden loom in Abra, with a wide waist tie and hand-rolled hems.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Piña Silk Scarf', 'price' => 1200.00, 'stock' => 15, 'photos' => 2,
            'description' => 'Pineapple-leaf silk in an open weave, finished with a hand-knotted fringe.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => "T'nalak Shawl", 'price' => 2950.00, 'stock' => 4, 'photos' => 3,
            'description' => 'Handwoven abaca in the T\'boli t\'nalak pattern, tie-dyed before the weave begins.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Benguet Single-Origin Coffee', 'price' => 520.00, 'stock' => 40, 'photos' => 2,
            'description' => 'Arabica grown in La Trinidad, washed, sun-dried and roasted in small batches. 250g bag.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Bagoong Balayan Jar', 'price' => 180.00, 'stock' => 25, 'photos' => 2,
            'description' => 'Fermented anchovy paste made the Batangas way, in a sealed glass jar. 250g.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Tablea Cacao Discs', 'price' => 340.00, 'stock' => 2, 'photos' => 3,
            'description' => 'Roasted, ground and moulded cacao discs, unsweetened. Ten to a pack.'],
        ['shop' => 'Alon Botanicals', 'category' => 'Health and Beauty', 'name' => 'Virgin Coconut Oil Soap', 'price' => 240.00, 'stock' => 60, 'photos' => 2,
            'description' => 'Cold-pressed coconut oil, cured six weeks, unscented. One 110g bar.'],
        ['shop' => 'Alon Botanicals', 'category' => 'Health and Beauty', 'name' => 'Ilang-Ilang Body Oil', 'price' => 760.00, 'stock' => 11, 'photos' => 3,
            'description' => 'Ilang-ilang distilled in Bohol, carried in sweet almond oil. 100ml amber bottle.'],
        // Hidden by its own status.
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Prototype Weave Sample', 'price' => 250.00, 'stock' => 5, 'photos' => 1, 'status' => 'draft',
            'description' => 'A loom sample, kept for reference while the pattern is worked out.'],
        // Hidden by its seller's status; the product itself is active.
        ['shop' => 'Bagong Uling Supplies', 'category' => 'Furniture and Office Equipment', 'name' => 'Uling Starter Bag', 'price' => 420.00, 'stock' => 30, 'photos' => 2,
            'description' => 'Kiln-dried charcoal in a paper sack, for grilling or for the kalan.'],
    ];

    public function run(): void
    {
        $sellers = $this->seedSellers();

        foreach (self::PRODUCTS as $row) {
            $this->seedProduct($row, $sellers);
        }

        $this->command?->info(
            'Demo catalogue: '.count($sellers).' shops, '.count(self::PRODUCTS)
            .' products ('.self::VISIBLE_PRODUCTS.' visible to the app).'
        );
    }

    /**
     * Create or refresh the demo shops, keyed by email so re-seeding updates
     * instead of duplicating.
     *
     * @return array<string, Seller> Keyed by business name, which is what the
     *                               product rows refer to.
     */
    private function seedSellers(): array
    {
        $sellers = [];

        foreach (self::SELLERS as $shop) {
            $status = $shop['status'] ?? 'approved';

            $seller = Seller::updateOrCreate(
                ['email' => $shop['email']],
                [
                    'last_name' => $shop['last_name'],
                    'first_name' => $shop['first_name'],
                    'sex' => $shop['sex'],
                    // Hashed by the model's cast. The value is the same `password`
                    // every factory and test account in this project uses.
                    'password' => 'password',
                    // Eleven characters, as the column demands: '09' plus nine
                    // digits taken from the shop's own email, so a re-run keeps the
                    // same number instead of inventing a new one each time.
                    'contact_no' => '09'.str_pad((string) (abs(crc32($shop['email'])) % 1000000000), 9, '0', STR_PAD_LEFT),
                    'birthday' => '1979-06-14',
                    'age' => 47,
                    'address_mode' => 'manual',
                    'province_name' => $shop['province'],
                    'municipality_name' => $shop['municipality'],
                    'barangay_name' => $shop['barangay'],
                    'street' => $shop['street'],
                    'house_number' => $shop['house_number'],
                    'business_name' => $shop['business_name'],
                    'line_of_business_id' => $this->categoryId($shop['category']),
                    // Both paths are NOT NULL. Nothing reads them: they are the
                    // seller's private uploads, and no demo account logs in.
                    'upload_id_path' => 'sellers/demo/'.Str::slug($shop['business_name']).'-id.jpg',
                    'business_permit_path' => 'sellers/demo/'.Str::slug($shop['business_name']).'-permit.jpg',
                    'status' => $status,
                ],
            );

            // Neither timestamp is fillable — the web flow writes them with
            // `forceFill` too (`Seller::markEmailVerified`) — so they are written
            // the same way here rather than made mass-assignable for a seeder's
            // sake. Every demo seller *has* confirmed their email, including the
            // unapproved one, whose status means "confirmed, waiting on admin"
            // rather than "never confirmed". An existing timestamp is kept, so
            // re-seeding never rewrites when an account was verified.
            $seller->forceFill([
                'email_verified_at' => $seller->email_verified_at ?? now(),
                'approved_at' => $status === 'approved' ? ($seller->approved_at ?? now()) : null,
            ])->save();

            $sellers[$shop['business_name']] = $seller;
        }

        return $sellers;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Seller>  $sellers
     */
    private function seedProduct(array $row, array $sellers): void
    {
        $product = Product::updateOrCreate(
            ['slug' => Str::slug($row['name'])],
            [
                'seller_id' => $sellers[$row['shop']]->id,
                'category_id' => $this->categoryId($row['category']),
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => $row['price'],
                'stock_quantity' => $row['stock'],
                'low_stock_threshold' => 5,
                'status' => $row['status'] ?? 'active',
            ],
        );

        $this->seedPhotos($product, (int) $row['photos']);
    }

    /**
     * Replace this product's placeholder photos with a fresh set.
     *
     * Only rows this seeder owns are removed — those whose path is a Picsum URL —
     * so re-running it against a product that has since been given real photos
     * through the seller's own upload flow leaves those photos alone.
     */
    private function seedPhotos(Product $product, int $count): void
    {
        $product->images()->where('path', 'like', 'https://picsum.photos/%')->delete();

        for ($index = 0; $index < $count; $index++) {
            ProductImage::create([
                'product_id' => $product->id,
                // Seeded from the slug, so the same product shows the same
                // placeholder on every run instead of a new random photo.
                'path' => 'https://picsum.photos/seed/zef-'.$product->slug.'-'.$index.'/1000/1000',
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * The id of a curated category label, or null if the label is not one of them.
     *
     * Returning null rather than throwing is deliberate: `products.category_id` is
     * nullable and the resource renders a null category as "Uncategorised", so a
     * typo here shows up as a visibly miscategorised demo product — which the
     * accompanying test fails on — instead of a crash that hides the cause.
     */
    private function categoryId(string $label): ?int
    {
        return Category::query()->where('label', $label)->value('id');
    }
}
```

### web/database\seeders\DemoOrderSeeder.php  (148 lines)

```php
<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderPlacer;
use Illuminate\Database\Seeder;

/**
 * Two orders for the demo buyer, so the app's Orders tab has something to show.
 *
 * ## Why this exists, and why it is the proof the extraction was worth doing
 *
 * The catalogue and the buyer were seeded, and `GET /api/orders` returned `[]` —
 * which the app renders as its honest "no orders yet" empty state, so the one live
 * read that is *about a person* could not be verified on a device at all. The tab
 * only becomes checkable once a buyer actually has parcels.
 *
 * It places them through `App\Services\OrderPlacer` — the same service the web
 * checkout and `POST /api/orders` call — rather than inserting rows by hand. That
 * is deliberate and it is the payoff of extracting those rules: the seeded orders
 * carry real prices from the locked rows, a real stock decrement with its ledger
 * entry, real payments, a real address snapshot and a real `ORD-…` reference,
 * because they are written by the code that writes orders. Hand-inserted rows
 * would be a second spelling of an order, and the demo data would be the only
 * thing that ever checked it.
 *
 * The first order spans **two shops on purpose**: it is the one thing about the
 * history that a single-shop fixture cannot demonstrate, and it is what proves
 * `OrderResource`'s seller-order keying on a real screen — two parcels, one order
 * number, the way a real checkout produces them.
 *
 * ## Two rules, borrowed from the seeder beside it
 *
 *  * **It refuses to run in production**, for a different reason than the
 *    credential does: this one *consumes stock*. A production database must not
 *    have inventory decremented and orders minted that nobody placed.
 *  * **It is idempotent**, keyed on the buyer already owning an order. Re-seeding
 *    never stacks a second demo history on top of the first, so `db:seed` stays
 *    safe to run repeatedly — and it says so instead of guessing.
 */
class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — demo orders consume stock and must never be written in production.'
            );

            return;
        }

        // Setup, but only what is missing. Calling these unconditionally looked
        // harmless ("both are idempotent") and was not: the catalogue seeder
        // *resets* the catalogue, so a second `db:seed` handed stock back to the
        // orders the first pass had placed — and, where it recreates rows, would
        // leave the demo history pointing at products that no longer exist. A
        // seeder that places orders must not re-lay the ground under them.
        if (! Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->exists()) {
            $this->call(DemoBuyerSeeder::class);
        }

        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->first();

        if ($buyer === null) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — the demo buyer is missing, so there is nobody to place an order for.'
            );

            return;
        }

        if (! $this->hasBuyableProducts()) {
            $this->call(DemoCatalogueSeeder::class);
        }

        if (Order::query()->where('buyer_id', $buyer->id)->exists()) {
            $this->command?->info('Demo orders: already present — left alone.');

            return;
        }

        // Buyable products, grouped by shop, newest shop order last so the pick is
        // stable. Nothing is hard-coded to a product or a price: the prices come
        // from the rows, inside the service, exactly as they do for a real basket.
        $bySeller = Product::query()
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->orderBy('id')
            ->get()
            ->groupBy('seller_id')
            ->filter(fn ($products) => $products->isNotEmpty())
            ->values();

        if ($bySeller->count() < 2) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — a two-shop order needs two shops with buyable stock.'
            );

            return;
        }

        $placer = app(OrderPlacer::class);

        // One checkout, two shops: the two-parcel case the history screen is built
        // around. Quantity 1 each, so a demo never eats a shop's whole stock.
        $acrossTwoShops = $placer->place($buyer, [
            ['product_id' => $bySeller[0]->first()->id, 'quantity' => 1],
            ['product_id' => $bySeller[1]->first()->id, 'quantity' => 1],
        ]);

        // And a second, single-shop order, so the list is a list rather than one row
        // — the state the app's newest-first ordering is visible in. A second
        // product from the same shop when there is one, so the two orders do not
        // read as duplicates.
        $single = $bySeller[0]->count() > 1 ? $bySeller[0][1] : $bySeller[0]->first();

        $placer->place($buyer, [
            ['product_id' => $single->id, 'quantity' => 1],
        ]);

        $this->command?->info(sprintf(
            'Demo orders: 2 placed for %s (order %s has %d parcels across 2 shops). %s',
            DemoBuyerSeeder::EMAIL,
            $acrossTwoShops->reference,
            $acrossTwoShops->sellerOrders()->count(),
            "What the app's Orders tab will list.",
        ));
    }

    /**
     * Whether the shelves have anything on them at all.
     *
     * What is on them is not this seeder's business; it only needs to know whether
     * the catalogue is empty, because an empty one is the single case where laying
     * a catalogue down is the right move rather than a destructive one.
     */
    private function hasBuyableProducts(): bool
    {
        return Product::query()
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->exists();
    }
}
```

### web/database\seeders\VoucherSeeder.php  (50 lines)

```php
<?php

namespace Database\Seeders;

use App\Enums\VoucherType;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * The curated starter vouchers.
 *
 * `value` is stored in centavos for fixed vouchers and as a whole percentage
 * for percent vouchers — never a float.
 */
class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $vouchers = [
            [
                'code' => 'ZEFANYANEW',
                'type' => VoucherType::Fixed,
                'value' => 20000,          // ₱200.00
                'min_spend' => null,
                'usage_limit' => null,
            ],
            [
                'code' => 'WELCOME10',
                'type' => VoucherType::Percent,
                'value' => 10,             // 10%
                'min_spend' => 100000,     // ₱1,000.00
                'usage_limit' => null,
            ],
        ];

        foreach ($vouchers as $voucher) {
            Voucher::updateOrCreate(
                ['code' => $voucher['code']],
                [
                    'type' => $voucher['type'],
                    'value' => $voucher['value'],
                    'min_spend' => $voucher['min_spend'],
                    'usage_limit' => $voucher['usage_limit'],
                    'is_active' => true,
                    'expires_at' => null,
                ],
            );
        }
    }
}
```

