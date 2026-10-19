<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\UnifiedLoginController;
use App\Http\Controllers\Buyer\RegisterBuyerController;
use App\Http\Controllers\Buyer\VerifyBuyerOtpController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\Logistics\RegisterLogisticsController;
use App\Http\Controllers\Logistics\VerifyLogisticsOtpController;
use App\Http\Controllers\Seller\RegisterSellerController;
use App\Http\Controllers\Seller\VerifySellerOtpController;
use App\Http\Controllers\Rider\ActivationController;
use App\Http\Controllers\Rider\DashboardController as RiderDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingPageController::class, 'index'])->name('home');

// Guest-only auth surfaces: any signed-in role is redirected to their dashboard.
// Logout stays outside this group.
Route::middleware('guest.role')->group(function (): void {
    Route::get('/register', function () {
        return view('Auth.Register-Type');
    })->name('register.type');

    Route::get('/register/buyer', function () {
        return redirect()->route('register.type', ['open' => 'buyer']);
    })->name('register.buyer');

    Route::post('/register/buyer', [RegisterBuyerController::class, 'store'])
        ->name('register.buyer.store')
        ->middleware('throttle:register');

    // Step 1 of 2: verify the emailed code.
    // GET: no-JS/deep-link OTP page so an unverified buyer can resume without
    //      re-registering (the modal flow verifies inline via JSON).
    // POST: validate the 6-digit code (JSON for the modal).
    // All three take a SIGNED URL (60 min) so the numeric id cannot be changed to
    // another person's account (see App\Support\SignedOtpLink).
    Route::get('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'show'])
        ->name('register.buyer.verify-otp.show')
        ->middleware(['signed', 'throttle:otp-show']);

    Route::post('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'verify'])
        ->name('register.buyer.verify-otp')
        ->middleware(['signed', 'throttle:otp-verify']);

    // POST: generate a fresh code + resend email (throttled).
    Route::post('/register/buyer/verify-otp/{buyer}/resend', [VerifyBuyerOtpController::class, 'resend'])
        ->name('register.buyer.verify-otp.resend')
        ->middleware(['signed', 'throttle:otp-resend']);

    // Step 2 of 2: shown after email is confirmed, while waiting on admin approval.
    Route::get('/register/buyer/pending', function () {
        return view('Buyer.register-buyer-pending');
    })->name('register.buyer.pending');

    // Seller registration routes
    Route::get('/register/seller', function () {
        return redirect()->route('register.type', ['open' => 'seller']);
    })->name('register.seller');

    Route::post('/register/seller', [RegisterSellerController::class, 'store'])
        ->name('register.seller.store')
        ->middleware('throttle:register');

    // GET: no-JS/deep-link OTP page so an unverified seller can resume without
    //      re-registering (the modal flow verifies inline via JSON).
    // All three take a SIGNED URL (60 min) so the id cannot be changed.
    Route::get('/register/seller/verify-otp/{seller}', [VerifySellerOtpController::class, 'show'])
        ->name('register.seller.verify-otp.show')
        ->middleware(['signed', 'throttle:otp-show']);

    Route::post('/register/seller/verify-otp/{seller}', [VerifySellerOtpController::class, 'verify'])
        ->name('register.seller.verify-otp')
        ->middleware(['signed', 'throttle:otp-verify']);

    Route::post('/register/seller/verify-otp/{seller}/resend', [VerifySellerOtpController::class, 'resend'])
        ->name('register.seller.verify-otp.resend')
        ->middleware(['signed', 'throttle:otp-resend']);

    Route::get('/register/seller/pending', function () {
        return view('Seller.register-seller-pending');
    })->name('register.seller.pending');

    // Logistics registration routes — same shape as the seller flow. The GET entry
    // point forwards to the register-type page and opens the logistics modal
    // (previously it rendered Auth.register-logistics, which never existed → 500).
    Route::get('/register/logistics', function () {
        return redirect()->route('register.type', ['open' => 'logistics']);
    })->name('register.logistics');

    Route::post('/register/logistics', [RegisterLogisticsController::class, 'store'])
        ->name('register.logistics.store')
        ->middleware('throttle:register');

    Route::get('/register/logistics/verify-otp/{logistics_provider}', [VerifyLogisticsOtpController::class, 'show'])
        ->name('register.logistics.verify-otp')
        ->middleware(['signed', 'throttle:otp-show']);

    Route::post('/register/logistics/verify-otp/{logistics_provider}', [VerifyLogisticsOtpController::class, 'verify'])
        ->name('register.logistics.verify-otp.store')
        ->middleware(['signed', 'throttle:otp-verify']);

    Route::post('/register/logistics/verify-otp/{logistics_provider}/resend', [VerifyLogisticsOtpController::class, 'resend'])
        ->name('register.logistics.verify-otp.resend')
        ->middleware(['signed', 'throttle:otp-resend']);

    Route::get('/register/logistics/pending', function () {
        return view('Logistics.register-logistics-pending');
    })->name('register.logistics.pending');

    // ============================================================================
    // Unified login entry point
    // ============================================================================
    // POST /login  →  Auth\UnifiedLoginController@login
    //   Tries seller guard first, then buyer. Redirects by role:
    //     seller → seller.dashboard
    //     buyer  → buyer.home
    // The shared public login modal submits here so the
    // public roles share a single auth backend.
    Route::post('/login', [UnifiedLoginController::class, 'login'])
        ->name('unified.login')
        ->middleware('throttle:login');

    // ============================================================================
    // Password reset — all roles (buyer, seller, logistics, admin)
    // ============================================================================
    // One entry point resolves the role from the email and uses that role's
    // password broker. `password.reset` is the route name Laravel's default
    // ResetPassword notification builds its link from, so do not rename it.
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->name('password.email')
        ->middleware('throttle:password-email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->name('password.update')
        ->middleware('throttle:password-update');

    // ============================================================================
    // Google Sign-In  →  Auth\GoogleAuthController
    // ============================================================================
    // /auth/google/redirect + /callback are ROLE-AGNOSTIC: the callback resolves
    // buyer | seller | logistics from the Google email. They power the single
    // "Continue with Google" button in the shared login modal.
    // /auth/google/{role}/redirect + /callback are the role-scoped pair for any
    // surface that already knows which panel is being signed in to.
    // LINK flows only — no account is ever created here, and the same
    // "approved" gate as password sign-in is applied before a session opens.
    Route::prefix('auth/google')->name('auth.google.')->middleware('throttle:google-auth')->group(function (): void {
        Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');

        Route::get('/{role}/redirect', [GoogleAuthController::class, 'redirectForRole'])
            ->where('role', implode('|', GoogleAuthController::roles()))
            ->name('role.redirect');

        Route::get('/{role}/callback', [GoogleAuthController::class, 'callbackForRole'])
            ->where('role', implode('|', GoogleAuthController::roles()))
            ->name('role.callback');
    });
});

// POST /logout  → clears every active guard (seller + buyer) and returns home.
Route::post('/logout', [UnifiedLoginController::class, 'logout'])
    ->name('unified.logout')
    ->middleware('throttle:logout');

// ── Rider activation (public link, 24h single-use) + dashboard ─────────
Route::get('/rider/activate/{token}', [ActivationController::class, 'show'])->name('rider.activate.show');
Route::post('/rider/activate/{token}', [ActivationController::class, 'store'])->name('rider.activate')->middleware('throttle:10,1');
Route::middleware(['auth:rider', 'rider.active'])->group(function (): void {
    Route::get('/rider/dashboard', [RiderDashboardController::class, 'index'])->name('rider.dashboard');
});
