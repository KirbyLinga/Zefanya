<?php
// Add to routes/web.php

use App\Http\Controllers\Buyer\LoginController;
use App\Http\Controllers\RegisterBuyerController;
use App\Http\Controllers\RegisterSellerController;
use App\Http\Controllers\VerifyBuyerOtpController;
use App\Http\Controllers\VerifySellerOtpController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('LandingPage.index');
})->name('home');

Route::get('/register', function () {
    return view('Auth.Register-Type');
})->name('register.type');

Route::get('/register/buyer', function () {
    return redirect()->route('register.type', ['open' => 'buyer']);
})->name('register.buyer');

Route::post('/register/buyer', [RegisterBuyerController::class, 'store'])
    ->name('register.buyer.store')
    ->middleware('throttle:5,1');

// The OTP entry form (also reached by the modal's "verify-otp.form" route).
Route::get('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'show'])
    ->name('register.buyer.verify-otp.form');

// Step 1 of 2: shown immediately after submitting, before email is confirmed.
// Legacy page kept for direct deep-links; modal flow uses the OTP modal instead.
Route::get('/register/buyer/check-email', function () {
    return view('Buyer.register-buyer-check-email');
})->name('register.buyer.check-email');

// POST: validate the 6-digit code (JSON for the modal).
Route::post('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'verify'])
    ->name('register.buyer.verify-otp');

// POST: generate a fresh code + resend email (throttled).
Route::post('/register/buyer/verify-otp/{buyer}/resend', [VerifyBuyerOtpController::class, 'resend'])
    ->name('register.buyer.verify-otp.resend')
    ->middleware('throttle:3,1');

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
    ->middleware('throttle:5,1');

Route::get('/register/seller/verify-otp/{seller}', [VerifySellerOtpController::class, 'show'])
    ->name('register.seller.verify-otp');

Route::post('/register/seller/verify-otp/{seller}', [VerifySellerOtpController::class, 'verify'])
    ->name('register.seller.verify-otp.store')
    ->middleware('throttle:10,1');

Route::post('/register/seller/verify-otp/{seller}/resend', [VerifySellerOtpController::class, 'resend'])
    ->name('register.seller.verify-otp.resend')
    ->middleware('throttle:3,5');

Route::get('/register/seller/pending', function () {
    return view('Seller.register-seller-pending');
})->name('register.seller.pending');

Route::get('/register/logistics', function () {
    return view('Auth.register-logistics');
})->name('register.logistics');

Route::get('/shop', function () {
    return view('LandingPage.index');
})->name('shop.browse');

/*
|--------------------------------------------------------------------------
| Buyer Authentication Routes
|--------------------------------------------------------------------------
| Guest-only login form + POST handler. The old stubs (redirect()->back()
| with a TODO) are deleted — this replaces them.
|
| The modal in login-modal.blade.php submits here via classic form POST.
| The controller also handles AJAX/JSON for programmatic clients.
|
| Named buyer.login / buyer.login.post / buyer.logout — the "buyer."
| prefix avoids colliding with the admin guard's "login" name.
*/

Route::middleware('guest:buyer')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('buyer.login');
    Route::post('/login', [LoginController::class, 'login'])
        ->name('buyer.login.submit')
        ->middleware('throttle:5,1');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->name('buyer.logout')
    ->middleware('auth:buyer');