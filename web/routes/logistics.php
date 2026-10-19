<?php

use App\Http\Controllers\Logistics\DashboardController;
use App\Http\Controllers\Logistics\DeliveryAssignmentsController;
use App\Http\Controllers\Logistics\DeliveryMonitoringController;
use App\Http\Controllers\Logistics\IncomingParcelsController;
use App\Http\Controllers\Logistics\ParcelSortingController;
use App\Http\Controllers\Logistics\RiderApplicationDocumentController;
use App\Http\Controllers\Logistics\RiderController;
use Illuminate\Support\Facades\Route;

/**
 * Logistics panel routes.
 * Mirrors routes/seller.php: prefix + name prefix, guarded by the logistics
 * session guard and the logistics-approved middleware.
 */
Route::prefix('logistics')->name('logistics.')->group(function (): void {
    Route::middleware(['auth:logistics', 'logistics.approved'])->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/riders', [RiderController::class, 'index'])->name('riders.index');
        Route::get('/riders/{riderApplication}', [RiderController::class, 'show'])->name('riders.show');
        Route::post('/riders/{riderApplication}/decision', [RiderController::class, 'decide'])->middleware('throttle:rider-decisions')->name('riders.decide');
        Route::get('/rider-applications/{riderApplication}/documents/{document}', [RiderApplicationDocumentController::class, 'show'])->whereIn('document', ['id', 'or-cr'])->name('rider-applications.documents.show');

        // ── Rider pickup: parcels waiting to be picked up ──────────────────
        Route::get('/incoming-parcels', [IncomingParcelsController::class, 'index'])->name('incoming-parcels.index');
        Route::post('/incoming-parcels/{seller_order}/pickup', [IncomingParcelsController::class, 'pickup'])->name('incoming-parcels.pickup');
        Route::post('/incoming-parcels/{seller_order}/to-sorting-center', [IncomingParcelsController::class, 'toSortingCenter'])->name('incoming-parcels.toSortingCenter');

        // ── Sorting center: sort parcels + assign delivery couriers ───────────
        Route::get('/parcel-sorting', [ParcelSortingController::class, 'index'])->name('parcel-sorting.index');
        Route::post('/parcel-sorting/{seller_order}/sort', [ParcelSortingController::class, 'sort'])->name('parcel-sorting.sort');
        Route::post('/parcel-sorting/{seller_order}/assign-delivery', [ParcelSortingController::class, 'assignDelivery'])->name('parcel-sorting.assignDelivery');

        // ── Rider delivery: delivery assignments ─────────────────────────────
        Route::get('/delivery-assignments', [DeliveryAssignmentsController::class, 'index'])->name('delivery-assignments.index');
        Route::post('/delivery-assignments/{seller_order}/start', [DeliveryAssignmentsController::class, 'startDelivery'])->name('delivery-assignments.start');
        Route::post('/delivery-assignments/{seller_order}/delivered', [DeliveryAssignmentsController::class, 'markDelivered'])->name('delivery-assignments.delivered');
        Route::get('/delivery-assignments/{seller_order}/failed', [DeliveryAssignmentsController::class, 'showMarkFailedForm'])->name('delivery-assignments.failed.form');
        Route::post('/delivery-assignments/{seller_order}/failed', [DeliveryAssignmentsController::class, 'markFailed'])->name('delivery-assignments.failed');

        // ── Read-only overview of claimed parcels + failure reasons ─────────
        Route::get('/delivery-monitoring', [DeliveryMonitoringController::class, 'index'])->name('delivery-monitoring.index');
    });
});
