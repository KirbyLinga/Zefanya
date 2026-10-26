<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\LogisticsHubService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(Request $request, LogisticsHubService $hubService): View
    {
        $provider = $request->user('logistics');
        $facilityKey = (string) $request->query('facility', 'all');
        $facility = $this->resolveFacility($provider->id, $facilityKey);
        $stations = $hubService->stationIdsForFacility($provider, $facility);

        $areaQuery = Area::query()->where('logistics_provider_id', $provider->id);
        if ($facility !== null) {
            $areaQuery->whereIn('station_hub_id', $stations);
        }
        $areaIds = $areaQuery->pluck('id');

        $courierQuery = Courier::query()->where('logistics_provider_id', $provider->id);
        if ($facility !== null) {
            $courierQuery->whereHas('areas', fn ($query) => $query->whereIn('areas.id', $areaIds));
        }
        $courierIds = (clone $courierQuery)->pluck('id');

        $parcelQuery = fn () => $this->parcelQuery($provider->id, $facility === null, $areaIds, $courierIds);
        $parcelsInHub = $parcelQuery()->where('status', OrderStatus::AtSortingCenter->value)->count();
        $parcelsInTransit = $parcelQuery()->whereIn('status', [
            OrderStatus::PickedUp->value,
            OrderStatus::Sorted->value,
            OrderStatus::AssignedToRider->value,
            OrderStatus::OutForDelivery->value,
        ])->count();
        $deliveredToday = StatusHistory::query()
            ->whereIn('seller_order_id', $parcelQuery()->select('seller_orders.id'))
            ->whereIn('to_status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->where('created_at', '>=', now()->startOfDay())
            ->distinct('seller_order_id')
            ->count('seller_order_id');
        $activeRiders = (clone $courierQuery)->where('is_available', true)->count();
        $recentParcels = $parcelQuery()->with('seller')->latest()->limit(5)->get();

        $provinceHubs = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->with('stations')
            ->orderBy('province_name')
            ->orderBy('name')
            ->get();
        $checklist = $hubService->checklist($provider->id);

        $pendingApplications = Rider::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('status', 'pending')
            ->whereNotNull('email_verified_at')
            ->latest()
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'created_at']);

        $notificationsTableExists = Schema::hasTable('notifications');
        $notifications = $notificationsTableExists
            ? $provider->notifications()->latest()->limit(8)->get()
            : collect();
        $unreadCount = $notificationsTableExists ? $provider->unreadNotifications()->count() : 0;

        return view('Logistics.Dashboard.index', [
            'provider' => $provider,
            'provinceHubs' => $provinceHubs,
            'facility' => $facility,
            'facilityKey' => $facilityKey,
            'checklist' => $checklist,
            'pendingApplications' => $pendingApplications,
            'parcelsInHub' => $parcelsInHub,
            'parcelsInTransit' => $parcelsInTransit,
            'deliveredToday' => $deliveredToday,
            'activeRiders' => $activeRiders,
            'recentParcels' => $recentParcels,
            'operationsReady' => $checklist['complete'],
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    private function resolveFacility(int $providerId, string $facilityKey): ?LogisticsHub
    {
        if ($facilityKey === 'all') {
            return null;
        }

        if (! preg_match('/^(province|station):(\d+)$/', $facilityKey, $matches)) {
            abort(404);
        }

        $hub = LogisticsHub::query()->where('logistics_provider_id', $providerId)->findOrFail((int) $matches[2]);
        abort_unless($hub->hub_type === ($matches[1] === 'province' ? LogisticsHub::PROVINCE : LogisticsHub::STATION), 404);

        return $hub;
    }

    private function parcelQuery(int $providerId, bool $allFacilities, $areaIds, $courierIds)
    {
        return SellerOrder::query()->heldBy($providerId)->where(function ($query) use ($providerId, $allFacilities, $areaIds, $courierIds): void {
            if ($allFacilities) {
                $query->where('custody_provider_id', $providerId)->orWhere('carrier_provider_id', $providerId)->orWhereHas('courier', fn ($couriers) => $couriers->where('logistics_provider_id', $providerId))
                    ->orWhereHas('deliveryCourier', fn ($couriers) => $couriers->where('logistics_provider_id', $providerId))
                    ->orWhereHas('destinationArea', fn ($areas) => $areas->where('logistics_provider_id', $providerId));

                return;
            }

            if ($areaIds->isEmpty() && $courierIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            if ($areaIds->isNotEmpty()) {
                $query->whereIn('destination_area_id', $areaIds);
            }
            if ($courierIds->isNotEmpty()) {
                $method = $areaIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                $query->{$method}('courier_id', $courierIds)
                    ->orWhereIn('delivery_courier_id', $courierIds);
            }
        });
    }
}
