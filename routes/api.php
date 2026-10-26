<?php

use App\Http\Controllers\Rider\ParcelIncidentController;
use App\Http\Controllers\Rider\RegistrationController;
use Illuminate\Support\Facades\Route;

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
