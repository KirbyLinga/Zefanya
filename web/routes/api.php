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

use App\Http\Controllers\Api\Auth\CourierLoginController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Chat\ChatController;
use App\Http\Controllers\Api\Courier\CourierJobController;
use App\Http\Controllers\Api\Courier\CourierMilestoneController;
use App\Http\Controllers\Api\Buyer\RegisterBuyerController;
use App\Http\Controllers\Api\Buyer\VerifyBuyerOtpController;
use App\Http\Controllers\Api\Cart\VoucherController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\PromotionController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Order\OrderMilestoneController;
use App\Http\Controllers\Api\Review\ReviewController;
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

// Couriers authenticate through a different table and a different guard.
// The route shape mirrors the buyer's: one path per role, because one guard
// is one provider — see CourierLoginController for the reasoning.
Route::post('/login/courier', [CourierLoginController::class, 'store'])
    ->name('api.login.courier')
    ->middleware('throttle:5,1,api-login-courier');

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

Route::get('/promotions', [PromotionController::class, 'index'])
    ->name('api.promotions.index')
    ->middleware('throttle:60,1,api-promotions');

// ── Authenticated ───────────────────────────────────────────────────────────

// Exists so the token has something to prove itself against: everything else
// here either precedes a token or is public, which would leave "is this token
// real?" unanswerable from outside.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [LoginController::class, 'show'])
        ->name('api.me');

    // The courier's equivalent — a separate path because the token holder may be
    // either a Buyer or a LogisticsProvider, and the controller checks which.
    Route::get('/me/courier', [CourierLoginController::class, 'show'])
        ->name('api.me.courier');

    Route::post('/logout/courier', [CourierLoginController::class, 'destroy'])
        ->name('api.logout.courier');

    // ── Courier jobs ─────────────────────────────────────────────────────────
    //
    // The parcels assigned to the signed-in courier. Scoped through
    // `assigned_courier_id` so a token cannot list another rider's jobs.
    // Own bucket — separate from the buyer's order reads so a courier
    // loading their dashboard cannot consume the buyer's allowance.
    Route::get('/courier/jobs', [CourierJobController::class, 'index'])
        ->name('api.courier.jobs.index')
        ->middleware('throttle:60,1,api-courier-jobs');

    // Courier records a milestone update — buyers poll the read endpoint below.
    // Own bucket: this is a write, and tight enough that a loop here only
    // records a job's status, not mint orders.
    Route::patch('/courier/jobs/{sellerOrder}/milestone', [CourierMilestoneController::class, 'update'])
        ->name('api.courier.jobs.milestone')
        ->middleware('throttle:30,1,api-courier-milestone');

    // Buyer reads the current milestone for one of their parcels.
    // Used by the tracker screen to poll while it is open.
    Route::get('/orders/{sellerOrder}/milestone', [OrderMilestoneController::class, 'show'])
        ->name('api.orders.milestone')
        ->middleware('throttle:60,1,api-orders-milestone');

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

    // ── Reviews ──────────────────────────────────────────────────────────────
    //
    // Two routes, one resource.
    //
    // The history bucket is lower than the catalogue's reads but consistent with
    // the order history: a person opens their review list occasionally, not in a
    // scroll loop. The write bucket is tighter — ten a minute is a very fast
    // reviewer — because a loop here writes rows, not pages.
    //
    // Route key is the seller_order_id (called {sellerOrder} for model binding),
    // because the app's own id for an order *is* the seller order's id. See the
    // controller doc for the full reasoning.
    Route::get('/reviews/mine', [ReviewController::class, 'index'])
        ->name('api.reviews.mine')
        ->middleware('throttle:60,1,api-reviews-index');

    Route::patch('/orders/{sellerOrder}/items/{product}/review', [ReviewController::class, 'upsert'])
        ->name('api.reviews.upsert')
        ->middleware('throttle:10,1,api-reviews-upsert');

    // ── Chat ─────────────────────────────────────────────────────────────────
    //
    // Two routes per thread: read history and send a message. The contact is
    // identified by display name today (see ChatMessage::keyBetweenBuyerAndContact
    // for why, and for the stable-id migration path).
    //
    // Separate throttle buckets per verb: reads are cheap (polling), writes
    // mint rows. 60 reads/min matches the catalogue; 20 sends/min is a fast
    // human and a slow bot.
    Route::get('/chat/{contact}', [ChatController::class, 'index'])
        ->name('api.chat.index')
        ->middleware('throttle:60,1,api-chat-read');

    Route::post('/chat/{contact}', [ChatController::class, 'store'])
        ->name('api.chat.store')
        ->middleware('throttle:20,1,api-chat-send');
});
