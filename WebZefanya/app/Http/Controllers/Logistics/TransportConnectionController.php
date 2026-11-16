<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\TransportConnection;
use App\Services\Logistics\TransportConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransportConnectionController extends Controller
{
    public function index(Request $request, TransportConnectionService $service): View
    {
        $provider = $request->user('logistics');
        abort_unless($provider->archived_at === null, 403);
        $connections = TransportConnection::query()
            ->where(function ($query) use ($provider): void {
                $query->where('operating_provider_id', $provider->id)
                    ->orWhereHas('originHub', fn ($hub) => $hub->where('logistics_provider_id', $provider->id))
                    ->orWhereHas('destinationHub', fn ($hub) => $hub->where('logistics_provider_id', $provider->id));
            })->with(['originHub.provider', 'destinationHub.provider', 'operatingProvider'])
            ->orderByDesc('id')->paginate(20);
        $hubs = LogisticsHub::query()->operational()
            ->where(function ($query) use ($provider): void {
                $query->where('logistics_provider_id', $provider->id)
                    ->orWhereHas('provider', fn ($partner) => $partner->whereHas('incomingLinks', fn ($link) => $link
                        ->where('origin_provider_id', $provider->id)->where('status', 'ACTIVE')));
            })->with(['provider', 'parent'])->orderBy('name')->get()
            ->filter(fn (LogisticsHub $hub) => $service->eligibleHub($hub));

        return view('Logistics.TransportConnections.index', compact('provider', 'connections', 'hubs'));
    }

    public function store(Request $request, TransportConnectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'origin_hub_id' => ['required', 'integer', 'exists:logistics_hubs,id'],
            'destination_hub_id' => ['required', 'integer', 'exists:logistics_hubs,id'],
            'transport_mode' => ['required', Rule::in(TransportConnection::TRANSPORT_MODES)],
            'operating_provider_id' => ['sometimes', 'required', 'integer'],
        ]);
        $service->create($request->user('logistics'), $data);

        return back()->with('success', 'Directional transport connection created.');
    }

    public function updateStatus(Request $request, int $connection, TransportConnectionService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user('logistics'), $connection, (bool) $data['is_active']);

        return back()->with('success', $data['is_active'] ? 'Connection activated.' : 'Connection deactivated. Saved parcel routes are retained.');
    }
}
