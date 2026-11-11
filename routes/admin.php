<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\HubApprovalController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\LogisticsRemittanceController;
use App\Http\Controllers\Admin\LostParcelController;
use App\Http\Controllers\Admin\OperationsAuditLogController;
use App\Http\Controllers\Admin\PartnershipController;
use App\Http\Controllers\Admin\PlatformSettingController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RegistrationController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\SellerPayoutController;
use App\Http\Controllers\Admin\SellerSettlementController;
use App\Http\Controllers\Admin\TransportOverviewController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\Admin\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
| Prefix 'admin' and name 'admin.' are applied in bootstrap/app.php.
|
| Roles (admins.role): super_admin (passes every check), admin, moderator, support.
|   - Any authenticated admin: dashboard and logout.
|   - admin / moderator (+ super_admin): registration queue and moderation.
|   - admin only (+ super_admin): private identity documents, settings,
|     settlements. Moderators review applications but must not open raw IDs.
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
        Route::get('/settings', [PlatformSettingController::class, 'index'])->name('settings.index');
        Route::patch('/settings/{platformSetting}', [PlatformSettingController::class, 'update'])->name('settings.update');
        // Read-only on purpose: the audit log has no edit or delete routes.
        Route::get('/audit-logs', [OperationsAuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/logistics-remittances', [LogisticsRemittanceController::class, 'index'])->name('logistics-remittances.index');
        Route::post('/logistics-remittances/{remittance}/confirm', [LogisticsRemittanceController::class, 'confirm'])->name('logistics-remittances.confirm');
        Route::post('/logistics-remittances/{remittance}/reject', [LogisticsRemittanceController::class, 'reject'])->name('logistics-remittances.reject');
        Route::post('/logistics-remittances/allocation-policy', [LogisticsRemittanceController::class, 'approveAllocationPolicy'])->name('logistics-remittances.allocation-policy');
        Route::post('/logistics-remittances/origin/{provider}/pay', [LogisticsRemittanceController::class, 'payOrigin'])->name('logistics-remittances.pay-origin');
        Route::post('/logistics-remittances/historical/{parcel}/reconcile', [LogisticsRemittanceController::class, 'reconcileHistorical'])->name('logistics-remittances.reconcile-historical');
        Route::post('/logistics-remittances/historical/{parcel}/pay-destination', [LogisticsRemittanceController::class, 'payDestination'])->name('logistics-remittances.pay-destination');
        Route::post('/logistics-remittances/origin-payments/{payout}/reverse', [LogisticsRemittanceController::class, 'reverseOriginPayment'])->name('logistics-remittances.reverse-origin-payment');

        // Read-only report: no route here moves money.
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FinanceController::class, 'export'])->middleware('throttle:20,1')->name('finance.export');

        Route::get('/seller-payouts', [SellerPayoutController::class, 'index'])->name('seller-payouts.index');
        Route::post('/seller-payouts/{payout}/pay', [SellerPayoutController::class, 'pay'])->name('seller-payouts.pay');
        Route::post('/seller-payouts/{payout}/reject', [SellerPayoutController::class, 'reject'])->name('seller-payouts.reject');

        // JSON-only and without a screen. The Seller Payouts page is the normal way to pay a
        // Seller, so these stay with super admins, who must give a reason for each direct payout.
        Route::middleware('admin.role:super_admin')->group(function (): void {
            Route::get('/settlements', [SellerSettlementController::class, 'index'])->name('settlements.index');
            Route::post('/settlements/remittances/{remittance}', [SellerSettlementController::class, 'store'])->name('settlements.store');
            Route::post('/settlements/{settlement}/confirm', [SellerSettlementController::class, 'confirm'])->name('settlements.confirm');
        });

        // Private identity documents. Admin-only: moderators can decide
        // applications but must not stream raw IDs/permits. {document} is
        // whitelisted here AND again in DocumentController; every access is
        // written to admin_document_access_logs.
        Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
        Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create');
        Route::get('/vouchers/code-availability', [VoucherController::class, 'codeAvailability'])
            ->middleware('throttle:30,1')->name('vouchers.code-availability');
        Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
        Route::get('/vouchers/{voucher}/edit', [VoucherController::class, 'edit'])->name('vouchers.edit');
        Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
        Route::post('/vouchers/{voucher}/end', [VoucherController::class, 'end'])->name('vouchers.end');
        Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');

        Route::get('/documents/{type}/{id}/{document}', [DocumentController::class, 'stream'])
            ->name('documents.stream')
            ->where('type', 'seller|logistics|buyer')
            ->where('document', 'id|business_permit|dti_permit|sec_certificate');
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

        // Read-only: no route here moves a parcel or changes custody.
        Route::get('/transport', [TransportOverviewController::class, 'index'])->name('transport.index');

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
        Route::post('/returns/{return}/reverse-route', [ReturnController::class, 'authorizeReverseRoute'])->name('returns.reverse-route');
        Route::post('/returns/{return}/dispute', [ReturnController::class, 'resolveDispute'])->name('returns.dispute');
        Route::post('/returns/{return}/fault', [ReturnController::class, 'setFault'])->name('returns.fault');
        Route::post('/returns/{return}/refund', [ReturnController::class, 'recordRefund'])->name('returns.refund');
        Route::post('/returns/{return}/refund/void', [ReturnController::class, 'voidRefund'])->name('returns.refund.void');
        Route::post('/return-charges/{charge}/settle', [ReturnController::class, 'settleCharge'])->name('return-charges.settle');
        Route::get('/returns/{return}/refund-proof', [ReturnController::class, 'refundProof'])->name('returns.refund-proof');
        Route::post('/returns/{return}/stock', [ReturnController::class, 'stock'])->name('returns.stock');
        Route::post('/returns/{return}/close', [ReturnController::class, 'close'])->name('returns.close');
        Route::post('/returns/{return}/collection-retry', [ReturnController::class, 'authorizeCollectionRetry'])->name('returns.collection-retry');
        Route::post('/returns/{return}/disposition', [ReturnController::class, 'recordDisposition'])->name('returns.disposition');
        Route::post('/returns/{return}/receipt-dispute/resume', [ReturnController::class, 'resumeHandover'])->name('returns.receipt-dispute.resume');
        Route::get('/returns/{return}/receipt-dispute/photos/{index}', [ReturnController::class, 'receiptDisputePhoto'])->whereNumber('index')->name('returns.receipt-dispute.photo');
        Route::get('/partnerships', [PartnershipController::class, 'index'])->name('partnerships.index');
        Route::post('/partnerships/exceptions/{exception}/resolve', [PartnershipController::class, 'resolveException'])->name('partnerships.exceptions.resolve');
        Route::post('/partnerships/{link}/approve', [PartnershipController::class, 'approve'])->name('partnerships.approve');
        Route::patch('/partnerships/{link}/priority', [PartnershipController::class, 'updatePriority'])->name('partnerships.priority');
        Route::post('/partnerships/{link}/revoke', [PartnershipController::class, 'revoke'])->name('partnerships.revoke');

        // Suspend / reactivate approved accounts
        Route::get('/users', [UserAccountController::class, 'index'])->name('users.index');
        Route::post('/users/buyer/{buyer}/suspend', [UserAccountController::class, 'suspendBuyer'])->name('users.buyer.suspend');
        Route::post('/users/buyer/{buyer}/reactivate', [UserAccountController::class, 'reactivateBuyer'])->name('users.buyer.reactivate');
        Route::post('/users/seller/{seller}/suspend', [UserAccountController::class, 'suspendSeller'])->name('users.seller.suspend');
        Route::post('/users/seller/{seller}/reactivate', [UserAccountController::class, 'reactivateSeller'])->name('users.seller.reactivate');
        Route::post('/users/logistics/{logistics_provider}/suspend', [UserAccountController::class, 'suspendLogistics'])->name('users.logistics.suspend');
        Route::post('/users/logistics/{logistics_provider}/reactivate', [UserAccountController::class, 'reactivateLogistics'])->name('users.logistics.reactivate');
        Route::post('/users/logistics/{logistics_provider}/rider-wind-down', [UserAccountController::class, 'riderWindDownLogistics'])->name('users.logistics.rider-wind-down');

        // ── Admin product oversight (SEL-13) ────────────────────────────────
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('/products/{product}/restrict', [ProductController::class, 'restrict'])->name('products.restrict');
        Route::post('/products/{product}/unrestrict', [ProductController::class, 'unrestrict'])->name('products.unrestrict');

        // ── Lost/damaged incident management (new) ──────────────────────────
        Route::prefix('incidents')->group(function (): void {
            Route::get('/', [IncidentController::class, 'index'])->name('incidents.index');
            Route::post('/confirm-lost', [IncidentController::class, 'confirmLost'])->name('incidents.confirm-lost');
            Route::get('/{incident}', [IncidentController::class, 'show'])->name('incidents.detail');
            Route::post('/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
            Route::post('/{incident}/reject', [IncidentController::class, 'reject'])->name('incidents.reject');
            Route::delete('/{incident}', [IncidentController::class, 'destroy'])->name('incidents.destroy');
        });

        // Parcels confirmed lost: liability, the manual compensation confirmation, found parcels.
        Route::prefix('lost-parcels')->group(function (): void {
            Route::get('/', [LostParcelController::class, 'index'])->name('lost-parcels.index');
            Route::post('/{case}/liability', [LostParcelController::class, 'assign'])->name('lost-parcels.assign');
            Route::post('/{case}/pay', [LostParcelController::class, 'pay'])->name('lost-parcels.pay');
            Route::post('/{case}/adjust', [LostParcelController::class, 'adjust'])->name('lost-parcels.adjust');
            Route::post('/{case}/disposition', [LostParcelController::class, 'disposition'])->name('lost-parcels.disposition');
        });
    });
});
