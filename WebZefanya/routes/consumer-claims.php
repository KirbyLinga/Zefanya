<?php

use App\Http\Controllers\ConsumerClaimController;
use App\Http\Middleware\EnsureConsumerClaimsAvailable;
use Illuminate\Support\Facades\Route;

// Included only inside the existing authenticated Buyer/Seller/Admin role groups.
// Raw numeric case IDs deliberately avoid querying absent tables during implicit binding.
Route::middleware(EnsureConsumerClaimsAvailable::class)->group(function (): void {
    Route::get('/consumer-claims', [ConsumerClaimController::class, 'index'])->name('consumer-claims.index');
    Route::get('/consumer-claims/{claimId}', [ConsumerClaimController::class, 'show'])->whereNumber('claimId')->name('consumer-claims.show');
    Route::post('/consumer-claims/{claimId}/updates', [ConsumerClaimController::class, 'update'])->whereNumber('claimId')->middleware('throttle:20,1')->name('consumer-claims.update');
    Route::get('/consumer-claims/{claimId}/updates/{updateId}/evidence/{attachment}', [ConsumerClaimController::class, 'evidence'])
        ->whereNumber(['claimId', 'updateId', 'attachment'])->name('consumer-claims.evidence');
});
