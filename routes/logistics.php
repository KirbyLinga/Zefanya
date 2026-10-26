<?php

use App\Http\Controllers\Logistics\AreaController;
use App\Http\Controllers\Logistics\CodRemittanceController;
use App\Http\Controllers\Logistics\DashboardController;
use App\Http\Controllers\Logistics\DeliveryAssignmentsController;
use App\Http\Controllers\Logistics\DeliveryMonitoringController;
use App\Http\Controllers\Logistics\DeliveryProofController;
use App\Http\Controllers\Logistics\HandoffController;
use App\Http\Controllers\Logistics\HubsController;
use App\Http\Controllers\Logistics\IncomingParcelsController;
use App\Http\Controllers\Logistics\ManifestController;
use App\Http\Controllers\Logistics\NotificationController;
use App\Http\Controllers\Logistics\ParcelScanController;
use App\Http\Controllers\Logistics\ParcelSortingController;
use App\Http\Controllers\Logistics\PartnershipController;
use App\Http\Controllers\Logistics\PsgcController;
use App\Http\Controllers\Logistics\ReturnController;
use App\Http\Controllers\Logistics\RiderApplicationController;
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

        Route::get('/cod-remittances', [CodRemittanceController::class, 'index'])->name('cod-remittances.index');
        Route::post('/cod-remittances/{remittance}/confirm', [CodRemittanceController::class, 'confirm'])->name('cod-remittances.confirm');

        Route::get('/hubs', [HubsController::class, 'index'])->name('hubs.index');
        Route::post('/hubs/{hub}/stations', [HubsController::class, 'storeStation'])->name('hubs.stations.store');
        Route::patch('/hubs/{hub}', [HubsController::class, 'update'])->name('hubs.update');
        Route::patch('/hubs/{hub}/status', [HubsController::class, 'updateStatus'])->name('hubs.status');

        // ── Cross-provider partnership requests (Phase 5.2) ─────────────────
        Route::get('/partnerships', [PartnershipController::class, 'index'])->name('partnerships.index');
        Route::post('/partnerships', [PartnershipController::class, 'store'])->name('partnerships.store');
        Route::post('/partnerships/{link}/accept', [PartnershipController::class, 'accept'])->name('partnerships.accept');
        Route::post('/partnerships/{link}/reject', [PartnershipController::class, 'reject'])->name('partnerships.reject');

        // ── Cross-provider parcel handoffs (Phase 5.3–5.4) ──────────────────
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/{return}', [ReturnController::class, 'show'])->name('returns.show');
        Route::post('/returns/{return}/scan', [ReturnController::class, 'scan'])->name('returns.scan');
        Route::post('/returns/{return}/origin', [ReturnController::class, 'origin'])->name('returns.origin');
        Route::post('/returns/{return}/handover', [ReturnController::class, 'handover'])->name('returns.handover');
        Route::post('/returns/{return}/manifest', [ReturnController::class, 'manifest'])->name('returns.manifest');
        Route::post('/returns/{return}/handoff', [ReturnController::class, 'handoff'])->name('returns.handoff');

        Route::get('/handoffs', [HandoffController::class, 'index'])->name('handoffs.index');
        Route::post('/handoffs/parcels', [HandoffController::class, 'store'])->name('handoffs.store');
        Route::get('/handoffs/{handoff}', [HandoffController::class, 'show'])->name('handoffs.show');
        Route::post('/handoffs/{handoff}/accept', [HandoffController::class, 'accept'])->name('handoffs.accept');
        Route::post('/handoffs/{handoff}/reject', [HandoffController::class, 'reject'])->name('handoffs.reject');
        Route::post('/handoffs/{handoff}/scan/verify', [HandoffController::class, 'verifyScan'])->name('handoffs.scan.verify');
        Route::post('/handoffs/{handoff}/dispatch', [HandoffController::class, 'dispatch'])->name('handoffs.dispatch');
        Route::post('/handoffs/{handoff}/receive', [HandoffController::class, 'receive'])->name('handoffs.receive');

        Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');

        Route::get('/scan', [ParcelScanController::class, 'index'])->name('scan.index');
        Route::post('/scan/verify', [ParcelScanController::class, 'verify'])->name('scan.verify');

        // ── Internal hub-to-hub manifests (same provider only) ──────────────
        Route::get('/manifests', [ManifestController::class, 'index'])->name('manifests.index');
        Route::get('/manifests/create', [ManifestController::class, 'create'])->name('manifests.create');
        Route::post('/manifests', [ManifestController::class, 'store'])->name('manifests.store');
        Route::get('/manifests/{manifest}', [ManifestController::class, 'show'])->name('manifests.show');
        Route::post('/manifests/{manifest}/items', [ManifestController::class, 'addItem'])->name('manifests.items.store');
        Route::delete('/manifests/{manifest}/items/{item}', [ManifestController::class, 'removeItem'])->name('manifests.items.destroy');
        Route::post('/manifests/{manifest}/dispatch', [ManifestController::class, 'dispatch'])->name('manifests.dispatch');
        Route::post('/manifests/{manifest}/scan/verify', [ManifestController::class, 'verifyScan'])->name('manifests.scan.verify');
        Route::get('/manifests/{manifest}/receive', [ManifestController::class, 'receiveIndex'])->name('manifests.receive.index');
        Route::post('/manifests/{manifest}/items/{item}/receive', [ManifestController::class, 'receiveStore'])->name('manifests.receive.store');

        // ── Rider pickup: parcels waiting to be picked up ──────────────────
        Route::post('/dispatch/{seller_order}/{role}', [IncomingParcelsController::class, 'retryDispatch'])->where('role', 'pickup|delivery')->name('dispatch.retry');
        Route::get('/incoming-parcels', [IncomingParcelsController::class, 'index'])->name('incoming-parcels.index');
        Route::post('/incoming-parcels/{seller_order}/assign-pickup-rider', [IncomingParcelsController::class, 'assignPickupRider'])->name('incoming-parcels.assignPickupRider');
        Route::post('/incoming-parcels/{seller_order}/confirm-pickup-dispatch', [IncomingParcelsController::class, 'confirmPickupDispatch'])->name('incoming-parcels.confirmPickupDispatch');
        Route::post('/incoming-parcels/{seller_order}/to-sorting-center', [IncomingParcelsController::class, 'toSortingCenter'])->name('incoming-parcels.toSortingCenter');

        // ── Sorting center: sort parcels + assign delivery couriers ───────────
        Route::get('/parcel-sorting', [ParcelSortingController::class, 'index'])->name('parcel-sorting.index');
        Route::get('/parcel-sorting/{seller_order}/verify-address', [ParcelSortingController::class, 'previewAddress'])->name('parcel-sorting.address.preview');
        Route::post('/parcel-sorting/{seller_order}/verify-address', [ParcelSortingController::class, 'verifyAddress'])->name('parcel-sorting.address.verify');
        Route::post('/parcel-sorting/{seller_order}/sort', [ParcelSortingController::class, 'sort'])->name('parcel-sorting.sort');
        Route::post('/parcel-sorting/{seller_order}/assign-delivery', [ParcelSortingController::class, 'assignDelivery'])->name('parcel-sorting.assignDelivery');

        // ── Rider delivery: delivery assignments ─────────────────────────────
        Route::get('/delivery-assignments', [DeliveryAssignmentsController::class, 'index'])->name('delivery-assignments.index');
        Route::post('/delivery-assignments/{seller_order}/reassign', [DeliveryAssignmentsController::class, 'reassign'])->name('delivery-assignments.reassign');
        Route::post('/delivery-assignments/{seller_order}/reschedule', [DeliveryAssignmentsController::class, 'reschedule'])->name('delivery-assignments.reschedule');
        Route::post('/delivery-assignments/{seller_order}/return', [DeliveryAssignmentsController::class, 'returnToSeller'])->name('delivery-assignments.return');

        // ── Read-only overview of claimed parcels + failure reasons ─────────
        Route::get('/delivery-monitoring', [DeliveryMonitoringController::class, 'index'])->name('delivery-monitoring.index');

        Route::post('/delivery-monitoring/{seller_order}/incidents', [DeliveryAssignmentsController::class, 'reportIncident'])
            ->name('parcels.incidents.store');

        // Logistics parcel detail (delivery + incident reporting).
        Route::get('/delivery-monitoring/{seller_order}', [DeliveryAssignmentsController::class, 'show'])
            ->name('delivery-monitoring.show');

        // ── Private proof-of-delivery evidence (provider-scoped) ────────────
        Route::get('/proofs/{delivery_proof}', [DeliveryProofController::class, 'show'])->name('proofs.show');

        // ── Delivery areas (rider → area link + parcel destination routing) ──
        Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
        Route::post('/areas', [AreaController::class, 'store'])->name('areas.store');
        Route::post('/areas/link-rider', [AreaController::class, 'linkBarangays'])->name('areas.couriers.linkBarangays');
        Route::patch('/areas/{area}', [AreaController::class, 'update'])->name('areas.update');
        Route::delete('/areas/{area}', [AreaController::class, 'destroy'])->name('areas.destroy');
        Route::post('/areas/{area}/couriers', [AreaController::class, 'assignCourier'])->name('areas.couriers.assign');
        Route::delete('/areas/{area}/couriers/{courier}', [AreaController::class, 'unassignCourier'])->name('areas.couriers.unassign');

        // ── PSGC API endpoints (server-side cached) ──
        Route::prefix('psgc')->name('psgc.')->group(function (): void {
            Route::get('/municipalities', [PsgcController::class, 'municipalities'])->name('municipalities');
            Route::get('/barangays', [PsgcController::class, 'barangays'])->name('barangays');
            Route::post('/validate', [PsgcController::class, 'validate'])->name('validate');
        });

        // ── Rider registration (Add Rider, company-scoped) ──────────────
        Route::get('/riders', [RiderController::class, 'index'])->name('riders.index');
        Route::get('/riders/create', [RiderController::class, 'create'])->name('riders.create');
        Route::post('/riders', [RiderController::class, 'store'])->name('riders.store');
        Route::post('/riders/{rider}/approve', [RiderController::class, 'approve'])->name('riders.approve');
        Route::post('/riders/{rider}/reject', [RiderController::class, 'reject'])->name('riders.reject');
        Route::post('/riders/{rider}/suspend', [RiderController::class, 'suspend'])->name('riders.suspend');
        Route::post('/riders/{rider}/deactivate', [RiderController::class, 'deactivate'])->name('riders.deactivate');
        Route::post('/riders/{rider}/reactivate', [RiderController::class, 'reactivate'])->name('riders.reactivate');
        Route::patch('/riders/{rider}/station', [RiderController::class, 'changeStation'])->name('riders.station');
        Route::post('/riders/{rider}/resend', [RiderController::class, 'resend'])->name('riders.resend');
        Route::get('/riders/{rider}', [RiderController::class, 'show'])->name('riders.show');
    });
});

// JSON application review uses the existing Logistics session and CSRF protection.
Route::prefix('api/logistics/rider-applications')->name('api.logistics.rider-applications.')
    ->middleware(['auth:logistics', 'throttle:60,1'])->group(function (): void {
        Route::get('/', [RiderApplicationController::class, 'index'])->name('index');
        Route::get('/{rider}/documents/{document}', [RiderApplicationController::class, 'document'])->whereIn('document', ['drivers_license', 'or_cr'])->name('document');
        Route::post('/{rider}/approve', [RiderApplicationController::class, 'approve'])->name('approve');
        Route::post('/{rider}/reject', [RiderApplicationController::class, 'reject'])->name('reject');
    });
