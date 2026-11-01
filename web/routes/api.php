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

use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Buyer\AccountSecurityController;
use App\Http\Controllers\Api\Buyer\AddressController;
use App\Http\Controllers\Api\Buyer\RegisterBuyerController;
use App\Http\Controllers\Api\Buyer\VerifyBuyerOtpController;
use App\Http\Controllers\Api\Buyer\WishlistController;
use App\Http\Controllers\Api\Cart\VoucherController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Courier\CourierJobController;
use App\Http\Controllers\Api\Courier\CourierLoginController;
use App\Http\Controllers\Api\Courier\CourierMilestoneController;
use App\Http\Controllers\Api\Courier\CourierRegistrationController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Psgc\PsgcController;
use App\Http\Controllers\Api\Review\ReviewController;
use App\Http\Controllers\Api\Seller\SellerOrderController;
use App\Http\Controllers\Api\Admin\AdminLogisticsController;
use Illuminate\Support\Facades\Route;

// ── Registration (public: there is no session to require yet) ───────────────

Route::post('/register/buyer', [RegisterBuyerController::class, 'store'])
    ->name('api.register.buyer.store')
    ->middleware(['throttle:api-register', 'throttle:api-uploads']);

Route::post('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'verify'])
    ->name('api.register.buyer.verify-otp')
    ->middleware('throttle:10,1,api-otp-verify');

Route::post('/register/buyer/verify-otp/{buyer}/resend', [VerifyBuyerOtpController::class, 'resend'])
    ->name('api.register.buyer.verify-otp.resend')
    ->middleware('throttle:3,5,api-otp-resend');

Route::post('/register/courier', [CourierRegistrationController::class, 'store'])
    ->name('api.register.courier.store')
    ->middleware(['throttle:api-register', 'throttle:api-uploads']);

Route::post('/register/logistics', [CourierRegistrationController::class, 'store'])
    ->name('api.register.logistics.store')
    ->middleware(['throttle:api-register', 'throttle:api-uploads']);

// ── Signing in (public: this is where a token comes from) ───────────────────

Route::post('/login/buyer', [LoginController::class, 'store'])
    ->name('api.login.buyer')
    ->middleware('throttle:api-login');

Route::post('/login/courier', [CourierLoginController::class, 'store'])
    ->name('api.login.courier')
    ->middleware('throttle:api-login');

Route::post('/login/logistics', [CourierLoginController::class, 'store'])
    ->name('api.login.logistics')
    ->middleware('throttle:api-login');

// ── Federated / Social Auth ──────────────────────────────────────────────────

Route::post('/auth/google', [GoogleAuthController::class, 'handle'])
    ->name('api.auth.google')
    ->middleware('throttle:api-google');

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

// ── PSGC Reference Data (public) ───────────────────────────────────────────
Route::get('/psgc/regions', [PsgcController::class, 'regions'])->name('api.psgc.regions')->middleware('throttle:60,1,api-psgc');
Route::get('/psgc/provinces', [PsgcController::class, 'provinces'])->name('api.psgc.provinces')->middleware('throttle:60,1,api-psgc');
Route::get('/psgc/cities', [PsgcController::class, 'cities'])->name('api.psgc.cities')->middleware('throttle:60,1,api-psgc');
Route::get('/psgc/barangays', [PsgcController::class, 'barangays'])->name('api.psgc.barangays')->middleware('throttle:60,1,api-psgc');

// ── Authenticated ───────────────────────────────────────────────────────────

// Exists so the token has something to prove itself against: everything else
// here either precedes a token or is public, which would leave "is this token
// real?" unanswerable from outside.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [LoginController::class, 'show'])
        ->name('api.me');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('api.logout');

    Route::get('/me/courier', [CourierLoginController::class, 'show'])
        ->name('api.me.courier');

    Route::post('/logout/courier', [CourierLoginController::class, 'destroy'])
        ->name('api.logout.courier');

    // Account security
    Route::post('/account/password', [AccountSecurityController::class, 'changePassword'])
        ->name('api.account.password')
        ->middleware('throttle:api-account-password');

    Route::post('/account/email', [AccountSecurityController::class, 'requestEmailChange'])
        ->name('api.account.email.request')
        ->middleware('throttle:3,5,api-account-email-request');

    Route::post('/account/email/verify', [AccountSecurityController::class, 'confirmEmailChange'])
        ->name('api.account.email.verify')
        ->middleware('throttle:10,1,api-account-email-verify');

    Route::post('/account/phone', [AccountSecurityController::class, 'requestPhoneChange'])
        ->name('api.account.phone.request')
        ->middleware('throttle:3,5,api-account-phone-request');

    Route::post('/account/phone/verify', [AccountSecurityController::class, 'confirmPhoneChange'])
        ->name('api.account.phone.verify')
        ->middleware('throttle:10,1,api-account-phone-verify');

    // Buyer delivery addresses
    Route::get('/buyer/addresses', [AddressController::class, 'index'])->name('api.buyer.addresses.index');
    Route::post('/buyer/addresses', [AddressController::class, 'store'])->name('api.buyer.addresses.store');
    Route::get('/buyer/addresses/{address}', [AddressController::class, 'show'])->name('api.buyer.addresses.show');
    Route::match(['put', 'patch'], '/buyer/addresses/{address}', [AddressController::class, 'update'])->name('api.buyer.addresses.update');
    Route::delete('/buyer/addresses/{address}', [AddressController::class, 'destroy'])->name('api.buyer.addresses.destroy');
    Route::patch('/buyer/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('api.buyer.addresses.default');

    // Reviews
    Route::get('/reviews/mine', [ReviewController::class, 'index'])->name('api.reviews.mine');
    Route::patch('/orders/{sellerOrder}/items/{product}/review', [ReviewController::class, 'upsert'])->name('api.reviews.upsert')
        ->middleware('throttle:api-uploads');

    // Wishlist
    Route::get('/buyer/wishlist', [WishlistController::class, 'index'])->name('api.buyer.wishlist.index');
    Route::post('/buyer/wishlist/{product}', [WishlistController::class, 'store'])->name('api.buyer.wishlist.store');
    Route::delete('/buyer/wishlist/{product}', [WishlistController::class, 'destroy'])->name('api.buyer.wishlist.destroy');

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('api.orders.store');
    Route::get('/orders/{sellerOrder}/milestone', [OrderController::class, 'milestone'])->name('api.orders.milestone');
    Route::post('/orders/{sellerOrder}/delivery-pass', [OrderController::class, 'deliveryPass'])->name('api.orders.delivery-pass');
    Route::get('/orders/{sellerOrder}/delivery-proof', [OrderController::class, 'deliveryProof'])->name('api.orders.delivery-proof');

    // Courier — requires approved status on top of auth:sanctum
    Route::middleware('courier.approved')->group(function () {
        Route::get('/courier/jobs', [CourierJobController::class, 'index'])->name('api.courier.jobs.index');
        Route::patch('/courier/jobs/{sellerOrder}/milestone', [CourierMilestoneController::class, 'update'])->name('api.courier.jobs.milestone')
            ->middleware(['throttle:api-delivery-verify', 'throttle:api-uploads']);
    });
});

// ── Versioned v1 Surface ──────────────────────────────────────────────────
Route::prefix('v1')->group(function () {
    // Auth
    Route::post('/auth/google', [GoogleAuthController::class, 'handle'])->name('api.v1.auth.google')
        ->middleware('throttle:api-google');

    // PSGC
    Route::get('/psgc/regions', [PsgcController::class, 'regions'])->name('api.v1.psgc.regions');
    Route::get('/psgc/provinces', [PsgcController::class, 'provinces'])->name('api.v1.psgc.provinces');
    Route::get('/psgc/cities', [PsgcController::class, 'cities'])->name('api.v1.psgc.cities');
    Route::get('/psgc/barangays', [PsgcController::class, 'barangays'])->name('api.v1.psgc.barangays');

    // Buyer
    Route::post('/buyer/register', [RegisterBuyerController::class, 'store'])->name('api.v1.buyer.register')
        ->middleware(['throttle:api-register', 'throttle:api-uploads']);
    Route::post('/buyer/login', [LoginController::class, 'store'])->name('api.v1.buyer.login')
        ->middleware('throttle:api-login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/buyer/me', [LoginController::class, 'show'])->name('api.v1.buyer.me');
        Route::post('/buyer/logout', [LoginController::class, 'destroy'])->name('api.v1.buyer.logout');

        Route::get('/buyer/addresses', [AddressController::class, 'index'])->name('api.v1.buyer.addresses.index');
        Route::post('/buyer/addresses', [AddressController::class, 'store'])->name('api.v1.buyer.addresses.store');
        Route::get('/buyer/addresses/{address}', [AddressController::class, 'show'])->name('api.v1.buyer.addresses.show');
        Route::match(['put', 'patch'], '/buyer/addresses/{address}', [AddressController::class, 'update'])->name('api.v1.buyer.addresses.update');
        Route::delete('/buyer/addresses/{address}', [AddressController::class, 'destroy'])->name('api.v1.buyer.addresses.destroy');
        Route::patch('/buyer/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('api.v1.buyer.addresses.default');

        Route::get('/buyer/reviews/mine', [ReviewController::class, 'index'])->name('api.v1.buyer.reviews.mine');
        Route::patch('/buyer/orders/{sellerOrder}/items/{product}/review', [ReviewController::class, 'upsert'])->name('api.v1.buyer.reviews.upsert')
            ->middleware('throttle:api-uploads');

        Route::get('/buyer/wishlist', [WishlistController::class, 'index'])->name('api.v1.buyer.wishlist.index');
        Route::post('/buyer/wishlist/{product}', [WishlistController::class, 'store'])->name('api.v1.buyer.wishlist.store');
        Route::delete('/buyer/wishlist/{product}', [WishlistController::class, 'destroy'])->name('api.v1.buyer.wishlist.destroy');

        Route::get('/buyer/orders', [OrderController::class, 'index'])->name('api.v1.buyer.orders.index');
        Route::post('/buyer/orders', [OrderController::class, 'store'])->name('api.v1.buyer.orders.store');
        Route::get('/buyer/orders/{sellerOrder}/milestone', [OrderController::class, 'milestone'])->name('api.v1.buyer.orders.milestone');
        Route::post('/buyer/orders/{sellerOrder}/delivery-pass', [OrderController::class, 'deliveryPass'])->name('api.v1.buyer.orders.delivery-pass');
        Route::get('/buyer/orders/{sellerOrder}/delivery-proof', [OrderController::class, 'deliveryProof'])->name('api.v1.buyer.orders.delivery-proof');
    });

    // Courier
    Route::post('/courier/register', [CourierRegistrationController::class, 'store'])->name('api.v1.courier.register')
        ->middleware(['throttle:api-register', 'throttle:api-uploads']);
    Route::post('/courier/login', [CourierLoginController::class, 'store'])->name('api.v1.courier.login')
        ->middleware('throttle:api-login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/courier/me', [CourierLoginController::class, 'show'])->name('api.v1.courier.me');
        Route::post('/courier/logout', [CourierLoginController::class, 'destroy'])->name('api.v1.courier.logout');

        Route::middleware('courier.approved')->group(function () {
            Route::get('/courier/jobs', [CourierJobController::class, 'index'])->name('api.v1.courier.jobs.index');
            Route::patch('/courier/jobs/{sellerOrder}/milestone', [CourierMilestoneController::class, 'update'])->name('api.v1.courier.jobs.milestone')
                ->middleware(['throttle:api-delivery-verify', 'throttle:api-uploads']);
        });
    });

    // Seller
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/seller/orders', [SellerOrderController::class, 'index'])->name('api.v1.seller.orders.index');
        Route::patch('/seller/orders/{sellerOrder}/confirm', [SellerOrderController::class, 'confirm'])->name('api.v1.seller.orders.confirm');
        Route::patch('/seller/orders/{sellerOrder}/ready', [SellerOrderController::class, 'ready'])->name('api.v1.seller.orders.ready');
    });

    // Admin
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/admin/logistics/pending', [AdminLogisticsController::class, 'pending'])->name('api.v1.admin.logistics.pending');
        Route::post('/admin/logistics/{logisticsProvider}/approve', [AdminLogisticsController::class, 'approve'])->name('api.v1.admin.logistics.approve');
        Route::post('/admin/logistics/{logisticsProvider}/reject', [AdminLogisticsController::class, 'reject'])->name('api.v1.admin.logistics.reject');
    });
});
