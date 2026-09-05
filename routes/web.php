<?php
// Add to routes/web.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisterBuyerController;
use App\Http\Controllers\VerifyBuyerOtpController;
use App\Http\Controllers\RegisterSellerController;
use App\Http\Controllers\VerifySellerOtpController;

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

Route::get('/login', function () {
    return redirect()->back();
})->name('login');

Route::post('/login', function () {
    // TODO: implement real authentication
    return redirect()->route('home');
})->name('login.post');