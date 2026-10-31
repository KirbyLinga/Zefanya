<?php

use App\Http\Controllers\Api\Buyer\LoginController;
use App\Http\Controllers\Api\Buyer\LogoutController;
use App\Http\Controllers\Api\Buyer\ProfileController;
use App\Http\Controllers\Api\Rider\LoginController as RiderLoginController;
use App\Http\Controllers\Api\Rider\LogoutController as RiderLogoutController;
use App\Http\Controllers\Api\Rider\ProfileController as RiderProfileController;
use App\Http\Controllers\Rider\ParcelIncidentController;
use App\Http\Controllers\Rider\RegistrationController;
use Illuminate\Support\Facades\Route;

/* --------------------------------------------------------------------------
 * Buyer API
 * --------------------------------------------------------------------------
 * Bearer-token auth only. Login and registration/OTP live OUTSIDE this group
 * (guests authenticate without a token). Everything else under /api/buyer/
 * requires a valid, non-expired, non-revoked bearer token with the "buyer"
 * ability; the framework's sanctum guard resolves the token and ApiRole then
 * asserts the token has the buyer ability.
 * -------------------------------------------------------------------------- */

// Guests authenticate with these routes; NO token required.
Route::prefix('buyer')->name('api.buyer.')->group(function (): void {
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:api-login')->name('login');
});

// Token-bearing routes.
Route::prefix('buyer')->name('api.buyer.')->group(function (): void {
    Route::middleware(['auth:sanctum', 'api.role:buyer'])->group(function (): void {
        Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
        Route::post('/logout', [LogoutController::class, 'store'])->name('logout');
    });
});

/* --------------------------------------------------------------------------
 * Rider API
 * --------------------------------------------------------------------------
 * Same shape as the buyer group. A token issued to a buyer cannot reach
 * these routes because the "rider" ability is missing.
 * -------------------------------------------------------------------------- */

// Guests authenticate with these routes; NO token required.
Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/login', [RiderLoginController::class, 'login'])->middleware('throttle:api-login')->name('login');
});

// Token-bearing routes.
Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::middleware(['auth:sanctum', 'api.role:rider'])->group(function (): void {
        Route::get('/profile', [RiderProfileController::class, 'index'])->name('profile');
        Route::post('/logout', [RiderLogoutController::class, 'store'])->name('logout');
    });
});

Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:register')->name('register');
    Route::post('/registration/resume', [RegistrationController::class, 'resume'])->middleware('throttle:register')->name('resume');
    Route::post('/registration/{rider}/verify-otp', [RegistrationController::class, 'verify'])
        ->middleware(['signed', 'throttle:otp-verify'])->name('verify');
    Route::post('/registration/{rider}/resend-otp', [RegistrationController::class, 'resend'])
        ->middleware(['signed', 'throttle:otp-resend'])->name('resend');
});

// Rider JSON reporting uses the existing session guard and CSRF protection.
Route::middleware(['web', 'auth:rider', 'rider.active'])->prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/parcels/{sellerOrder}/incidents', [ParcelIncidentController::class, 'store'])
        ->name('parcels.incidents.store');
});
