<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\LogisticsHubService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request, LogisticsHubService $hubService): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'metric' => ['nullable', Rule::in(['hub', 'transit', 'delivered', 'riders'])],
        ]);
        $timezone = StoreSale::TIMEZONE;
        $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->setTimezone(config('app.timezone')) : null;
        $to = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->setTimezone(config('app.timezone')) : null;
        $dateFilter = function ($query, string $column) use ($from, $to): void {
            $query->when($from, fn ($q) => $q->where($column, '>=', $from))
                ->when($to, fn ($q) => $q->where($column, '<=', $to));
        };
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
        $hubQuery = $parcelQuery()->where('status', OrderStatus::AtSortingCenter->value);
        $transitQuery = $parcelQuery()->whereIn('status', [
            OrderStatus::PickedUp->value,
            OrderStatus::Sorted->value,
            OrderStatus::AssignedToRider->value,
            OrderStatus::OutForDelivery->value,
        ]);
        $dateFilter($hubQuery, 'seller_orders.updated_at');
        $dateFilter($transitQuery, 'seller_orders.updated_at');
        $deliveryEvents = StatusHistory::query()
            ->select('seller_order_id')
            ->whereIn('to_status', [OrderStatus::Delivered->value, OrderStatus::Completed->value]);
        if ($from === null && $to === null) {
            $today = CarbonImmutable::now($timezone);
            $deliveryEvents->whereBetween('created_at', [
                $today->startOfDay()->setTimezone(config('app.timezone')),
                $today->endOfDay()->setTimezone(config('app.timezone')),
            ]);
        } else {
            $dateFilter($deliveryEvents, 'created_at');
        }
        $deliveredQuery = $parcelQuery()->whereIn('seller_orders.id', $deliveryEvents);
        $riderQuery = (clone $courierQuery)->where('is_available', true);
        $dateFilter($riderQuery, 'couriers.updated_at');
        $queries = ['hub' => $hubQuery, 'transit' => $transitQuery, 'delivered' => $deliveredQuery, 'riders' => $riderQuery];
        $parcelsInHub = (clone $hubQuery)->count();
        $parcelsInTransit = (clone $transitQuery)->count();
        $deliveredToday = (clone $deliveredQuery)->count();
        $activeRiders = (clone $riderQuery)->count();
        $metric = $filters['metric'] ?? null;
        $metricResults = $metric === null ? null : (clone $queries[$metric])->orderByDesc('id')->paginate(20)->withQueryString();
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
            'metric' => $metric,
            'metricResults' => $metricResults,
            'dateFrom' => $filters['from'] ?? '',
            'dateTo' => $filters['to'] ?? '',
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
