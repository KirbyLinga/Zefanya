<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\OrderMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderMonitoringController extends Controller
{
    public function index(Request $request, OrderMonitoringService $monitor): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'provider' => ['nullable', 'integer', 'exists:logistics_providers,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'attention' => ['nullable', Rule::in(array_keys(OrderMonitoringService::ATTENTION))],
            'summary' => ['nullable', Rule::in(OrderMonitoringService::SUMMARY_FILTERS)],
        ]);
        $shipments = $monitor->query($filters)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('Admin.OrderMonitoring.index', [
            'shipments' => $shipments, 'filters' => $filters, 'monitor' => $monitor,
            'summary' => $monitor->summaryCounts(),
            'custody' => $monitor->custody($shipments->getCollection()),
            'providers' => LogisticsProvider::query()->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    public function show(SellerOrder $sellerOrder, OrderMonitoringService $monitor): View
    {
        $shipment = $monitor->query()->findOrFail($sellerOrder->id);
        $shipment->load(['parcelRoute.originHub', 'parcelRoute.destinationHub']);
        $legs = $shipment->parcelRoute?->legs ?? [];
        $hubs = LogisticsHub::query()->whereIn('id', collect($legs)->pluck('hub_id')->filter()->unique())->pluck('name', 'id');

        return view('Admin.OrderMonitoring.show', [
            'shipment' => $shipment, 'monitor' => $monitor,
            'custodian' => $monitor->custody(collect([$shipment]))[$shipment->id] ?? null,
            'siblings' => SellerOrder::query()->where('order_id', $shipment->order_id)->with('seller')->orderBy('id')->paginate(20, ['*'], 'shipments_page')->withQueryString(),
            'items' => $shipment->manifestItems()->with(['manifest.originHub', 'manifest.destinationHub', 'recoveredHub'])->reorder()->orderBy('id')->paginate(20, ['*'], 'receipts_page')->withQueryString(),
            'handoffs' => $shipment->parcelHandoffs()->with(['originProvider', 'destinationProvider', 'sendingHub', 'receivingHub'])->reorder()->orderBy('id')->paginate(20, ['*'], 'handoffs_page')->withQueryString(),
            'activity' => $monitor->activity($shipment),
            'financials' => $monitor->financials($shipment), 'legs' => $legs, 'hubs' => $hubs,
        ]);
    }
}
