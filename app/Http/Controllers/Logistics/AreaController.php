<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Logistics\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Delivery-area management for the signed-in logistics provider.
 *
 * Areas are the bridge between the sorting center and the riders: the sorting
 * screen matches a parcel's destination municipality to one of these areas,
 * and riders (couriers) are linked to the area they serve. Every action is
 * scoped to the acting provider — one provider can never see or edit another's
 * areas.
 */
class AreaController extends Controller
{
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $areas = Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->withCount('couriers')
            ->orderBy('name')
            ->get();

        $couriers = Courier::query()
            ->where('logistics_provider_id', $provider->id)
            ->with('area')
            ->orderBy('name')
            ->get();

        return view('Logistics.Areas.index', [
            'provider' => $provider,
            'areas' => $areas,
            'couriers' => $couriers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = $request->user('logistics');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
        ]);

        Area::query()->updateOrCreate(
            [
                'logistics_provider_id' => $provider->id,
                'municipality' => $data['municipality'],
            ],
            [
                'name' => $data['name'],
                'is_active' => true,
            ],
        );

        return back()->with('success', 'Area saved.');
    }

    public function update(Request $request, Area $area): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless($area->logistics_provider_id === $provider->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $area->update([
            'name' => $data['name'],
            'municipality' => $data['municipality'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Area updated.');
    }

    public function destroy(Request $request, Area $area): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless($area->logistics_provider_id === $provider->id, 403);

        $area->delete();

        return back()->with('success', 'Area removed.');
    }

    /**
     * Link a rider (courier fleet record) to an area. Pass courier_id to set,
     * or omit it to clear the rider's area.
     */
    public function assignCourier(Request $request, Area $area): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless($area->logistics_provider_id === $provider->id, 403);

        $data = $request->validate([
            'courier_id' => ['required', 'integer'],
        ]);

        $courier = Courier::query()
            ->where('logistics_provider_id', $provider->id)
            ->findOrFail($data['courier_id']);

        $courier->update(['area_id' => $area->id]);

        return back()->with('success', "{$courier->name} assigned to {$area->name}.");
    }
}
