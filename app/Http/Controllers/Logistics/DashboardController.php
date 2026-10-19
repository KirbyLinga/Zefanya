<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Logistics dashboard home.
     *
     * Layout mirrors the seller dashboard (hero + stat cards + panels), but
     * every operational stat is an honest null/empty state: no courier,
     * parcel, or delivery tables exist yet, so no real numbers can be
     * derived. Only profile data from logistics_providers is real today.
     * Wire real queries here once the courier/parcel pipeline tables ship.
     */
    public function index(): View
    {
        $provider = Auth::guard('logistics')->user();

        // --- TODO: wire to real tables once they exist ---
        // couriers (approved, provider-scoped), parcels/shipments (hub volume),
        // delivery status history (activity trend, delivered today).
        $parcelsInHub = null;
        $parcelsInTransit = null;
        $deliveredToday = null;
        $activeRiders = null;
        $recentParcels = collect();

        // Notifications behave like the seller panel: only query if the
        // Laravel notifications table exists (Notifiable is on the model,
        // but the table is not migrated in this project yet).
        $notificationsTableExists = Schema::hasTable('notifications');
        $notifications = $notificationsTableExists
            ? $provider->notifications()->latest()->limit(8)->get()
            : collect();
        $unreadCount = $notificationsTableExists
            ? $provider->unreadNotifications()->count()
            : 0;

        return view('Logistics.Dashboard.index', [
            'provider' => $provider,
            'parcelsInHub' => $parcelsInHub,
            'parcelsInTransit' => $parcelsInTransit,
            'deliveredToday' => $deliveredToday,
            'activeRiders' => $activeRiders,
            'recentParcels' => $recentParcels,
            'operationsReady' => false,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
