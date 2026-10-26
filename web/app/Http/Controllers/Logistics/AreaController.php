<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\SellerOrder;
use App\Services\LogisticsHubService;
use App\Services\PhilippineLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Delivery-area management for the signed-in logistics provider.
 *
 * Areas are the bridge between the sorting center and the riders: the sorting
 * screen matches a parcel's destination barangay to one of these areas,
 * and riders (couriers) are linked to the area they serve. Every action is
 * scoped to the acting provider — one provider can never see or edit another's
 * areas.
 */
class AreaController extends Controller
{
    public function __construct(
        private readonly PhilippineLocationService $locationService
    ) {}

    public function index(Request $request, LogisticsHubService $hubs): View
    {
        $provider = $request->user('logistics');
        $convertingArea = $request->filled('convert') ? Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->whereNull('barangay_code')
            ->findOrFail($request->integer('convert')) : null;
        $stationId = $request->integer('station') ?: null;
        $selectedStation = null;
        if ($stationId !== null) {
            $selectedStation = LogisticsHub::query()
                ->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::STATION)
                ->findOrFail($stationId);
        }

        $areas = Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->when($selectedStation, fn ($query) => $query->where('station_hub_id', $selectedStation->id))
            ->with(['station', 'couriers'])
            ->withCount('couriers')
            ->orderBy('name')
            ->get();

        $stations = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('approval_status', '<>', LogisticsHub::APPROVAL_REJECTED)
            ->orderBy('province_name')
            ->orderBy('municipality')
            ->get();

        $couriers = Courier::query()
            ->where('logistics_provider_id', $provider->id)
            ->with(['areas', 'rider.station'])
            ->withWorkload()
            ->orderBy('name')
            ->get();

        $pendingParcels = SellerOrder::query()->heldBy((int) $provider->id)
            ->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp])
            ->with('seller:id,barangay_code,municipality_name')->get(['id', 'seller_id']);
        $pendingByBarangay = $pendingParcels->countBy(fn ($parcel) => $parcel->seller?->barangay_code ?? '');
        $pendingByMunicipality = $pendingParcels->countBy(fn ($parcel) => $parcel->seller?->municipality_name ?? '');
        $pendingPickups = $areas->mapWithKeys(fn (Area $area) => [
            $area->id => filled($area->barangay_code)
                ? ($pendingByBarangay[$area->barangay_code] ?? 0)
                : ($pendingByMunicipality[$area->municipality] ?? 0),
        ]);

        return view('Logistics.Areas.index', [
            'provider' => $provider,
            'convertingArea' => $convertingArea,
            'areas' => $areas,
            'couriers' => $couriers,
            'pendingPickups' => $pendingPickups,
            'stations' => $stations,
            'selectedStation' => $selectedStation,
            'coverageSchemaAvailable' => $hubs->coverageSchemaAvailable(),
            'riderLinksAvailable' => Schema::hasTable('area_courier'),
            'verifiedProvinceCode' => $provider->hasVerifiedProvince() ? (string) $provider->province_code : null,
            'verifiedProvinceName' => $provider->hasVerifiedProvince() ? (string) $provider->province_name : null,
            'coverageClaims' => ! $hubs->coverageSchemaAvailable() ? collect() : Area::query()->where('logistics_provider_id', $provider->id)->where('is_active', true)->whereNotNull('barangay_code')->with('station')->get()->map(fn ($a) => ['code' => $a->barangay_code, 'station' => $a->station?->name ?? 'Unassigned']),
        ]);
    }

    public function store(Request $request, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        $data = $this->validateCoverage($request, $provider->id);
        DB::transaction(function () use ($provider, $data, $hubs): void {
            $hubs->lockProvider($provider->id);
            foreach ($data['barangay_codes'] ?? [$data['barangay_code']] as $code) {
                $hubs->saveCoverage($provider->id, array_replace($data, ['barangay_code' => $code]));
            }
        }, 3);

        return back()->with('success', 'Barangay coverage saved. Next: link riders to these barangays.');
    }

    public function update(Request $request, Area $area, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless((int) $area->logistics_provider_id === (int) $provider->id, 403);
        $data = $this->validateCoverage($request, $provider->id);
        if (! isset($data['barangay_code'])) {
            throw ValidationException::withMessages(['barangay_code' => 'Select one barangay when editing coverage.']);
        }
        $wasLegacy = blank($area->barangay_code);
        $area = $hubs->saveCoverage($provider->id, $data, $area);

        if ($wasLegacy) {
            return to_route('logistics.areas.index', ['station' => $area->station_hub_id])
                ->with('success', 'Legacy area converted to barangay coverage.');
        }

        return back()->with('success', 'Barangay coverage updated.');
    }

    public function destroy(Request $request, Area $area, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless((int) $area->logistics_provider_id === (int) $provider->id, 403);
        $hubs->removeCoverage($provider->id, $area);

        return back()->with('success', 'Area removed.');
    }

    /**
     * Link a rider (courier fleet record) to an active coverage area.
     * Use unassignCourier to clear the link.
     */
    public function assignCourier(Request $request, Area $area, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless((int) $area->logistics_provider_id === (int) $provider->id, 403);
        $data = $request->validate(['courier_id' => ['required', 'integer']]);
        $hubs->linkCourierToAreas($provider->id, $data['courier_id'], [$area->id]);

        return back()->with('success', 'Rider linked to barangay.');
    }

    public function linkBarangays(Request $request, LogisticsHubService $hubs): RedirectResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer'],
            'area_ids' => ['required', 'array', 'min:1', 'max:200'],
            'area_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $hubs->linkCourierToAreas($request->user('logistics')->id, $data['courier_id'], $data['area_ids']);

        return back()->with('success', 'Rider linked to the selected barangays. Existing links were kept.');
    }

    public function unassignCourier(Request $request, Area $area, Courier $courier, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        abort_unless((int) $area->logistics_provider_id === (int) $provider->id, 403);
        abort_unless((int) $courier->logistics_provider_id === (int) $provider->id, 403);
        $hubs->unlinkCourierFromArea($provider->id, $area, $courier);

        return back()->with('success', 'Rider unlinked from this barangay. Existing parcel assignments were kept.');
    }

    /**
     * @return array{name: string, city_municipality_code: string, municipality_name: ?string, station_hub_id: int|string, is_active?: bool, barangay_codes?: array<int, string>, barangay_code?: string}
     */
    private function validateCoverage(Request $request, int $providerId): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:120'],
            'city_municipality_code' => ['required', 'string', 'max:20'],
            'barangay_code' => ['required_without:barangay_codes', 'string', 'max:20'],
            'barangay_codes' => ['sometimes', 'required', 'array', 'min:1', 'max:200'],
            'barangay_codes.*' => ['required', 'string', 'max:20', 'distinct'],
            'municipality_name' => ['nullable', 'string', 'max:120'],
            'station_hub_id' => [
                'required', 'integer',
                Rule::exists('logistics_hubs', 'id')->where('logistics_provider_id', $providerId)
                    ->where('hub_type', LogisticsHub::STATION)
                    ->whereNot('approval_status', LogisticsHub::APPROVAL_REJECTED),
            ],
        ];

        if ($request->isMethod('patch') || $request->isMethod('put')) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules, [
            'city_municipality_code.required' => 'Select the city/municipality to cover.',
        ]);

        // Server-side PSGC validation of barangay codes
        $station = LogisticsHub::query()
            ->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::STATION)
            ->findOrFail($validated['station_hub_id']);

        $provider = $request->user('logistics');
        if ($provider && $provider->hasVerifiedProvince()
            && (string) $station->province_code !== (string) $provider->province_code) {
            throw ValidationException::withMessages([
                'station_hub_id' => 'Coverage must use a Station Hub inside your verified province.',
            ]);
        }

        $barangayCodes = $validated['barangay_codes'] ?? [$validated['barangay_code']];

        if ($station->province_code && $station->region_code) {
            // Verify the city/municipality belongs to the station's province
            try {
                $cities = $this->locationService->getCitiesMunicipalities(
                    (string) $station->province_code,
                    (string) $station->region_code
                );
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages([
                    'city_municipality_code' => 'PSGC validation unavailable. Cannot verify municipality. Please try again later.',
                ]);
            }

            $city = collect($cities)->firstWhere('code', $validated['city_municipality_code']);
            if (! $city) {
                throw ValidationException::withMessages([
                    'city_municipality_code' => 'The selected municipality does not belong to the Station Hub province.',
                ]);
            }
            $municipalityName = $city['name'];

            // Validate each barangay code
            try {
                $barangays = $this->locationService->getBarangays($validated['city_municipality_code']);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages([
                    'barangay_code' => 'PSGC validation unavailable. Cannot verify barangay codes. Please try again later.',
                ]);
            }

            foreach ($barangayCodes as $code) {
                $barangay = collect($barangays)->firstWhere('code', $code);
                if (! $barangay) {
                    throw ValidationException::withMessages([
                        'barangay_code' => "Barangay code {$code} is invalid or does not belong to the selected municipality.",
                        'barangay_codes' => "Barangay code {$code} is invalid or does not belong to the selected municipality.",
                    ]);
                }
            }
        } else {
            throw ValidationException::withMessages([
                'station_hub_id' => 'The selected Station Hub must have PSGC province and region codes configured.',
            ]);
        }

        $validated['municipality_name'] = $municipalityName;
        $validated['name'] = filled($validated['name'] ?? null) ? $validated['name'] : $municipalityName;

        return $validated;
    }
}
