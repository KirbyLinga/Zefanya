<?php

use App\Http\Controllers\Rider\AccountController;
use App\Http\Controllers\Rider\DashboardController;
use App\Http\Controllers\Rider\DeliveryAssignmentsController;
use App\Http\Controllers\Rider\DeliveryHistoryController;
use App\Http\Controllers\Rider\DeliveryProofController;
use App\Http\Controllers\Rider\DigitalWaybillController;
use App\Http\Controllers\Rider\NotificationController;
use App\Http\Controllers\Rider\ParcelIncidentController;
use App\Http\Controllers\Rider\ParcelScanController;
use App\Http\Controllers\Rider\PickupAssignmentsController;
use App\Http\Controllers\Rider\ReturnPickupController;
use Illuminate\Support\Facades\Route;

/**
 * Rider panel routes.
 * Mirrors routes/logistics.php: prefix + name prefix, guarded by the rider
 * session guard and the rider-operational middleware. The public activation
 * link stays in routes/web.php.
 */
Route::prefix('rider')->name('rider.')->group(function (): void {
    Route::middleware(['auth:rider', 'rider.active'])->group(function (): void {
        Route::get('/', fn () => redirect()->route('rider.dashboard'))->name('home');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/pickups', [PickupAssignmentsController::class, 'index'])->name('pickups.index');
        Route::post('/pickups/{sellerOrder}/confirm', [PickupAssignmentsController::class, 'confirm'])->name('pickups.confirm');

        Route::post('/pickups/{sellerOrder}/report-failure', [PickupAssignmentsController::class, 'reportFailure'])->name('pickups.reportFailure');

        Route::get('/return-pickups', [ReturnPickupController::class, 'index'])->name('return-pickups.index');
        Route::post('/return-pickups/{return}/collect', [ReturnPickupController::class, 'collect'])->name('return-pickups.collect');

        Route::get('/scan', [ParcelScanController::class, 'index'])->name('scan.index');
        Route::post('/scan/verify', [ParcelScanController::class, 'verify'])->name('scan.verify');

        Route::get('/waybills/{sellerOrder}', [DigitalWaybillController::class, 'show'])->name('waybills.show');

        Route::get('/deliveries', [DeliveryAssignmentsController::class, 'index'])->name('deliveries.index');
        Route::post('/deliveries/{sellerOrder}/start', [DeliveryAssignmentsController::class, 'start'])->name('deliveries.start');
        Route::get('/deliveries/{sellerOrder}/proof', [DeliveryAssignmentsController::class, 'proofForm'])->name('deliveries.proof');
        Route::post('/deliveries/{sellerOrder}/otp', [DeliveryAssignmentsController::class, 'sendDeliveryOtp'])->middleware('throttle:5,1')->name('deliveries.otp');
        Route::post('/deliveries/{sellerOrder}/deliver', [DeliveryAssignmentsController::class, 'deliver'])->name('deliveries.deliver');
        Route::get('/deliveries/{sellerOrder}/failed', [DeliveryAssignmentsController::class, 'failedForm'])->name('deliveries.failed.form');
        Route::post('/deliveries/{sellerOrder}/failed', [DeliveryAssignmentsController::class, 'failed'])->name('deliveries.failed');

        Route::get('/history', [DeliveryHistoryController::class, 'index'])->name('history.index');
        // Private evidence is streamed only to the rider who submitted it.
        Route::get('/proofs/{deliveryProof}', [DeliveryProofController::class, 'show'])->name('proofs.show');
        Route::get('/account', [AccountController::class, 'show'])->name('account.show');

        // Notification centre. Literal routes come before the wildcard
        // {notification} routes so "read-all" can never be captured as an id.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
        Route::get('/notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.markRead');
        Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');

        // Notification centre. Literal routes come before the wildcard
        // {notification} routes so "read-all" can never be captured as an id.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
        Route::get('/notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.markRead');
        Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    });
});

// ── Lost/damaged reporting ──────────────────────────────────────────
Route::post('/parcels/{sellerOrder}/report-incident', [ParcelIncidentController::class, 'store'])
    ->middleware(['auth:rider', 'rider.active'])
    ->name('parcels.incidents.store');
