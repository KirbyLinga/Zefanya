<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\HubApprovalController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\PartnershipController;
use App\Http\Controllers\Admin\RegistrationController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\SellerSettlementController;
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

    Route::middleware('admin.role:admin')->group(function (): void {
        Route::get('/settlements', [SellerSettlementController::class, 'index'])->name('settlements.index');
        Route::post('/settlements/remittances/{remittance}', [SellerSettlementController::class, 'store'])->name('settlements.store');
        Route::post('/settlements/{settlement}/confirm', [SellerSettlementController::class, 'confirm'])->name('settlements.confirm');
    });

    Route::middleware('admin.role:admin,moderator')->group(function () {
        // Unified registration queue (buyers + sellers + logistics)
        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::post('/registrations/buyer/{buyer}/approve', [RegistrationController::class, 'approveBuyer'])->name('registrations.buyer.approve');
        Route::post('/registrations/buyer/{buyer}/reject', [RegistrationController::class, 'rejectBuyer'])->name('registrations.buyer.reject');
        Route::post('/registrations/seller/{seller}/approve', [RegistrationController::class, 'approveSeller'])->name('registrations.seller.approve');
        Route::post('/registrations/seller/{seller}/reject', [RegistrationController::class, 'rejectSeller'])->name('registrations.seller.reject');
        Route::post('/registrations/logistics/{logistics_provider}/approve', [RegistrationController::class, 'approveLogistics'])->name('registrations.logistics.approve');
        Route::post('/registrations/logistics/{logistics_provider}/reject', [RegistrationController::class, 'rejectLogistics'])->name('registrations.logistics.reject');

        // Hub Approvals (Logistics Hubs & Stations) + Admin Province Hub provisioning
        Route::get('/hubs', [HubApprovalController::class, 'index'])->name('hubs.index');
        Route::post('/hubs/province/provision', [HubApprovalController::class, 'provisionProvinceHub'])->name('hubs.province.provision');
        Route::post('/hubs/{hub}/approve', [HubApprovalController::class, 'approve'])->name('hubs.approve');
        Route::post('/hubs/{hub}/reject', [HubApprovalController::class, 'reject'])->name('hubs.reject');
        Route::post('/hubs/location-changes/{change}/approve', [HubApprovalController::class, 'approveLocationChange'])->name('hubs.location-changes.approve');
        Route::post('/hubs/location-changes/{change}/reject', [HubApprovalController::class, 'rejectLocationChange'])->name('hubs.location-changes.reject');
        Route::post('/users/logistics/{logistics_provider}/province', [HubApprovalController::class, 'changeProvince'])->name('users.logistics.province');

        // Cross-provider partnership approvals (Phase 5.2)
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::post('/returns/{return}/origin', [ReturnController::class, 'verifyOrigin'])->name('returns.origin');
        Route::post('/returns/{return}/resolve', [ReturnController::class, 'resolve'])->name('returns.resolve');
        Route::post('/returns/{return}/stock', [ReturnController::class, 'stock'])->name('returns.stock');
        Route::get('/partnerships', [PartnershipController::class, 'index'])->name('partnerships.index');
        Route::post('/partnerships/exceptions/{exception}/resolve', [PartnershipController::class, 'resolveException'])->name('partnerships.exceptions.resolve');
        Route::post('/partnerships/{link}/approve', [PartnershipController::class, 'approve'])->name('partnerships.approve');
        Route::patch('/partnerships/{link}/priority', [PartnershipController::class, 'updatePriority'])->name('partnerships.priority');
        Route::post('/partnerships/{link}/revoke', [PartnershipController::class, 'revoke'])->name('partnerships.revoke');

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

        // ── Lost/damaged incident management (new) ──────────────────────────
        Route::prefix('incidents')->group(function (): void {
            Route::get('/', [IncidentController::class, 'index'])->name('incidents.index');
            Route::get('/{incident}', [IncidentController::class, 'show'])->name('incidents.detail');
            Route::post('/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
            Route::post('/{incident}/reject', [IncidentController::class, 'reject'])->name('incidents.reject');
            Route::delete('/{incident}', [IncidentController::class, 'destroy'])->name('incidents.destroy');
        });
    });
});
