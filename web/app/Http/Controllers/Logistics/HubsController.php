<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsHubLocationChange;
use App\Services\LogisticsHubService;
use App\Services\PhilippineLocationService;
use App\Services\ProviderProvinceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HubsController extends Controller
{
    public function index(Request $request, ProviderProvinceService $provinces): View
    {
        $provider = $request->user('logistics');
        $provinceHubs = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->whereNull('archived_at')
            ->with(['pendingLocationChange', 'stations.areas', 'stations.pendingLocationChange'])
            ->withCount(['stations'])
            ->orderBy('province_name')
            ->orderBy('name')
            ->get();

        foreach ($provinceHubs as $provinceHub) {
            foreach ($provinceHub->stations as $station) {
                $station->loadCount(['areas', 'couriers']);
            }
        }

        return view('Logistics.Hubs.index', [
            'provider' => $provider,
            'provinceHubs' => $provinceHubs,
            'liveProvinceHub' => $provinces->activeProvinceHub($provider),
            'provinceLocked' => $provinces->isProvinceLocked($provider),
        ]);
    }

    public function storeStation(Request $request, int $hub, PhilippineLocationService $locationService): RedirectResponse
    {
        $provider = $request->user('logistics');
        $provinceHub = $this->ownedHub($provider->id, $hub);
        abort_unless($provinceHub->hub_type === LogisticsHub::PROVINCE, 404);
        abort_if($provinceHub->isArchived(), 404);

        // A Station Hub may only be added under the provider's live approved Province Hub.
        if (! $provinceHub->isLiveProvinceHub() || ! $provinceHub->isApproved() || ! $provinceHub->is_active) {
            throw ValidationException::withMessages([
                'parent_hub_id' => 'Station Hubs can only be added under an approved and active Province Hub.',
            ]);
        }

        if ($provider->hasVerifiedProvince()
            && (string) $provinceHub->province_code !== (string) $provider->province_code) {
            throw ValidationException::withMessages([
                'parent_hub_id' => 'Station Hubs must be created inside your verified service province.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city_municipality_code' => ['required', 'string', 'max:20'],
            'barangay_code' => ['required', 'string', 'max:20'],
            'municipality_name' => ['nullable', 'string', 'max:120'],
            'barangay_name' => ['nullable', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'location_details' => ['nullable', 'string', 'max:255'],
        ], [
            'street.required' => 'The street address field is required.',
            'city_municipality_code.required' => 'Select the city/municipality for this Station Hub.',
            'barangay_code.required' => 'Select the barangay for this Station Hub.',
        ]);

        if (blank($provinceHub->region_code) || blank($provinceHub->province_code)) {
            throw ValidationException::withMessages([
                'parent_hub_id' => 'This Province Hub has no PSGC location codes. Update the Province Hub before adding Station Hubs.',
            ]);
        }

        // The parent Province Hub's codes are authoritative. Client-submitted
        // region/province values are ignored so a station can never be placed
        // outside its parent's province.
        $resolved = $locationService->validateAndResolve([
            'region_code' => (string) $provinceHub->region_code,
            'province_code' => (string) $provinceHub->province_code,
            'city_municipality_code' => $data['city_municipality_code'],
            'barangay_code' => $data['barangay_code'],
            'region_name' => $provinceHub->region_name,
            'province_name' => $provinceHub->province_name,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
        ]);

        $municipality = $resolved['municipality_name'];
        $barangayName = $resolved['barangay_name'];

        LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $provinceHub->id,
            'name' => $data['name'],
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $provinceHub->region_code,
            'region_name' => $resolved['region_name'] ?: $provinceHub->region_name,
            'province_code' => $provinceHub->province_code,
            'province_name' => $resolved['province_name'] ?: $provinceHub->province_name,
            'city_municipality_code' => $data['city_municipality_code'],
            'municipality' => $municipality,
            'barangay_code' => $data['barangay_code'],
            'barangay_name' => $barangayName ?: null,
            'street' => $data['street'],
            'building' => $data['building'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'location_details' => $data['location_details'] ?? null,
            'approval_status' => LogisticsHub::APPROVAL_PENDING,
            'is_active' => false,
        ]);

        return back()->with('success', 'Station Hub submitted for Admin approval.');
    }

    public function update(Request $request, int $hub, PhilippineLocationService $locationService): RedirectResponse
    {
        $provider = $request->user('logistics');
        $logisticsHub = $this->ownedHub($provider->id, $hub);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'location_details' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];

        if ($logisticsHub->hub_type === LogisticsHub::PROVINCE) {
            // Province is Admin-verified and read-only. Logistics may only adjust
            // the facility address inside the locked province.
            $rules += [
                'region_code' => ['nullable', 'string', 'max:20'],
                'region_name' => ['nullable', 'string', 'max:120'],
                'province_code' => ['nullable', 'string', 'max:20'],
                'province_name' => ['nullable', 'string', 'max:120'],
                'city_municipality_code' => ['required', 'string', 'max:20'],
                'municipality_name' => ['nullable', 'string', 'max:120'],
                'barangay_code' => ['required', 'string', 'max:20'],
                'barangay_name' => ['nullable', 'string', 'max:120'],
            ];
        } else {
            $rules += [
                'city_municipality_code' => ['required', 'string', 'max:20'],
                'municipality_name' => ['nullable', 'string', 'max:120'],
                'barangay_code' => ['required', 'string', 'max:20'],
                'barangay_name' => ['nullable', 'string', 'max:120'],
            ];
        }

        $data = $request->validate($rules, [
            'street.required' => 'The street address field is required.',
            'city_municipality_code.required' => 'Select the city/municipality for this hub.',
            'barangay_code.required' => 'Select the barangay for this hub.',
        ]);

        if ($logisticsHub->hub_type === LogisticsHub::PROVINCE) {
            abort_if($logisticsHub->isArchived(), 404);

            // Reject forged province changes even if the form posts a different code.
            $attemptedProvince = $data['province_code'] ?? null;
            if ($provider->hasVerifiedProvince() && filled($attemptedProvince)
                && (string) $attemptedProvince !== (string) $provider->province_code) {
                abort(403, 'Your service province is locked. Contact an administrator to change it.');
            }

            $lockedProvinceCode = (string) ($provider->province_code ?: $logisticsHub->province_code);
            $lockedRegionCode = (string) ($logisticsHub->region_code ?: ($data['region_code'] ?? ''));

            if (blank($lockedProvinceCode) || blank($lockedRegionCode)) {
                throw ValidationException::withMessages([
                    'province_code' => 'This Province Hub has no verified province. Contact an administrator.',
                ]);
            }

            $resolved = $locationService->validateAndResolve([
                'region_code' => $lockedRegionCode,
                'province_code' => $lockedProvinceCode,
                'city_municipality_code' => (string) $data['city_municipality_code'],
                'barangay_code' => (string) $data['barangay_code'],
                'region_name' => $logisticsHub->region_name ?: ($data['region_name'] ?? null),
                'province_name' => $provider->province_name ?: $logisticsHub->province_name,
                'municipality_name' => $data['municipality_name'] ?? null,
                'barangay_name' => $data['barangay_name'] ?? null,
            ]);

            $address = [
                'region_code' => $lockedRegionCode,
                'region_name' => $resolved['region_name'] ?: $logisticsHub->region_name,
                'province_code' => $lockedProvinceCode,
                'province_name' => $resolved['province_name'] ?: ($provider->province_name ?: $logisticsHub->province_name),
                'city_municipality_code' => (string) $data['city_municipality_code'],
                'municipality' => $resolved['municipality_name'],
                'barangay_code' => (string) $data['barangay_code'],
                'barangay_name' => $resolved['barangay_name'],
                'street' => $data['street'],
                'building' => $data['building'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
            ];
        } else {
            $parent = $logisticsHub->parent;

            if (! $parent || blank($parent->region_code) || blank($parent->province_code)) {
                throw ValidationException::withMessages([
                    'edit_hub_id' => 'Update the parent Province Hub location before editing this Station Hub.',
                ]);
            }

            $resolved = $locationService->validateAndResolve([
                'region_code' => (string) $parent->region_code,
                'province_code' => (string) $parent->province_code,
                'city_municipality_code' => (string) $data['city_municipality_code'],
                'barangay_code' => (string) $data['barangay_code'],
                'region_name' => $parent->region_name,
                'province_name' => $parent->province_name,
                'municipality_name' => $data['municipality_name'] ?? null,
                'barangay_name' => $data['barangay_name'] ?? null,
            ]);

            $address = [
                'region_code' => $parent->region_code,
                'region_name' => $resolved['region_name'] ?: $parent->region_name,
                'province_code' => $parent->province_code,
                'province_name' => $resolved['province_name'] ?: $parent->province_name,
                'city_municipality_code' => (string) $data['city_municipality_code'],
                'municipality' => $resolved['municipality_name'],
                'barangay_code' => (string) $data['barangay_code'],
                'barangay_name' => $resolved['barangay_name'],
                'street' => $data['street'],
                'building' => $data['building'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
            ];
        }

        $locationChanged = $this->locationChanged($logisticsHub, $address);
        $requiresApproval = $logisticsHub->isApproved() && $locationChanged;

        DB::transaction(function () use ($logisticsHub, $data, $address, $requiresApproval): void {
            $logisticsHub->update([
                'name' => $data['name'],
                'location_details' => $data['location_details'] ?? null,
            ]);

            if ($requiresApproval) {
                $proposal = $address + [
                    'status' => LogisticsHubLocationChange::STATUS_PENDING,
                    'requested_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'rejection_reason' => null,
                ];

                $pending = $logisticsHub->locationChanges()
                    ->where('status', LogisticsHubLocationChange::STATUS_PENDING)
                    ->latest('id')
                    ->first();

                if ($pending) {
                    $pending->update($proposal);
                } else {
                    $logisticsHub->locationChanges()->create($proposal);
                }

                return;
            }

            $logisticsHub->update($address);
            $logisticsHub->locationChanges()
                ->where('status', LogisticsHubLocationChange::STATUS_PENDING)
                ->delete();
        });

        if ($requiresApproval) {
            return back()->with('success', 'Location change submitted for Admin approval. The current approved location remains active.');
        }

        return back()->with('success', 'Hub details updated.');
    }

    private function locationChanged(LogisticsHub $hub, array $address): bool
    {
        foreach (['region_code', 'province_code', 'city_municipality_code', 'barangay_code', 'street', 'building', 'landmark', 'postal_code'] as $field) {
            if ((string) ($hub->{$field} ?? '') !== (string) ($address[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function updateStatus(Request $request, int $hub, LogisticsHubService $service): RedirectResponse
    {
        $provider = $request->user('logistics');
        $logisticsHub = $this->ownedHub($provider->id, $hub);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        try {
            $service->activate($logisticsHub, $active);
        } catch (ValidationException $exception) {
            if (! $active) {
                return back()->with('error', $exception->getMessage());
            }
            throw $exception;
        }

        return back()->with('success', $active ? 'Hub activated.' : 'Hub deactivated.');
    }

    private function ownedHub(int $providerId, int $hubId): LogisticsHub
    {
        return LogisticsHub::query()
            ->where('logistics_provider_id', $providerId)
            ->findOrFail($hubId);
    }
}
