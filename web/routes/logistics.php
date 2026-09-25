<?php

use App\Http\Controllers\Logistics\DashboardController;
use Illuminate\Support\Facades\Route;

/**
 * Logistics panel routes.
 * Mirrors routes/seller.php: prefix + name prefix, guarded by the logistics
 * session guard and the logistics-approved middleware. A public
 * login/verify group is intentionally absent — logistics authentication is
 * a future phase (see project notes).
 */
Route::prefix('logistics')->name('logistics.')->group(function (): void {
    Route::middleware(['auth:logistics', 'logistics.approved'])->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    });
});
