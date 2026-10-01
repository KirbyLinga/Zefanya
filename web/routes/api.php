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

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('api.logout');
});
