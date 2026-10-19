<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\RegistrationController;
use App\Http\Controllers\Admin\UserAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
| Prefix 'admin' and name 'admin.' are applied in bootstrap/app.php.
|
| Roles (admins.role): super_admin (passes every check), admin, moderator, support.
|   - Any authenticated admin: dashboard, logout.
|   - admin / moderator (+ super_admin): registration queue and private documents.
|   - support: dashboard only, until a support-specific module exists.
*/

Route::middleware('guest.role')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->name('login.post')
        ->middleware('throttle:login');
});

Route::middleware('auth:admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('throttle:logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('admin.role:admin,moderator')->group(function () {
        // Unified registration queue (buyers + sellers + logistics)
        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::post('/registrations/buyer/{buyer}/approve', [RegistrationController::class, 'approveBuyer'])->name('registrations.buyer.approve');
        Route::post('/registrations/buyer/{buyer}/reject', [RegistrationController::class, 'rejectBuyer'])->name('registrations.buyer.reject');
        Route::post('/registrations/seller/{seller}/approve', [RegistrationController::class, 'approveSeller'])->name('registrations.seller.approve');
        Route::post('/registrations/seller/{seller}/reject', [RegistrationController::class, 'rejectSeller'])->name('registrations.seller.reject');
        Route::post('/registrations/logistics/{logistics_provider}/approve', [RegistrationController::class, 'approveLogistics'])->name('registrations.logistics.approve');
        Route::post('/registrations/logistics/{logistics_provider}/reject', [RegistrationController::class, 'rejectLogistics'])->name('registrations.logistics.reject');

        // Admin-only document streaming from private disk.
        // {document} is whitelisted here AND again in DocumentController.
        Route::get('/documents/{type}/{id}/{document}', [DocumentController::class, 'stream'])
            ->name('documents.stream')
            ->where('type', 'seller|logistics|buyer')
            ->where('document', 'id|business_permit|dti_permit');

        // Suspend / reactivate approved accounts
        Route::get('/users', [UserAccountController::class, 'index'])->name('users.index');
        Route::post('/users/buyer/{buyer}/suspend', [UserAccountController::class, 'suspendBuyer'])->name('users.buyer.suspend');
        Route::post('/users/buyer/{buyer}/reactivate', [UserAccountController::class, 'reactivateBuyer'])->name('users.buyer.reactivate');
        Route::post('/users/seller/{seller}/suspend', [UserAccountController::class, 'suspendSeller'])->name('users.seller.suspend');
        Route::post('/users/seller/{seller}/reactivate', [UserAccountController::class, 'reactivateSeller'])->name('users.seller.reactivate');
        Route::post('/users/logistics/{logistics_provider}/suspend', [UserAccountController::class, 'suspendLogistics'])->name('users.logistics.suspend');
        Route::post('/users/logistics/{logistics_provider}/reactivate', [UserAccountController::class, 'reactivateLogistics'])->name('users.logistics.reactivate');
    });
});
