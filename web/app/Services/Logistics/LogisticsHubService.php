<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Services\Shared\PhilippineLocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsHubService
{
    public function __construct(private readonly PhilippineLocationService $locations = new PhilippineLocationService) {}

    public function coverageSchemaAvailable(): bool
    {
        return Schema::hasColumns('areas', ['barangay_code', 'coverage_validated_at', 'active_barangay_code']);
    }

    /** Serialize coverage, activation and assignment decisions on a stable provider row. */
    public function lockProvider(int $providerId): void
    {
        LogisticsProvider::query()->whereKey($providerId)->lockForUpdate()->firstOrFail();
    }

    public function saveCoverage(int $providerId, array $data, ?Area $existing = null): Area
    {
        if (! $this->coverageSchemaAvailable()) {
            throw ValidationException::withMessages(['coverage' => 'Ask an administrator to apply the pending Barangay Coverage migrations before configuring coverage.']);
        }
        try {
            return DB::transaction(function () use ($providerId, $data, $existing): Area {
                $this->lockProvider($providerId);
                $provider = LogisticsProvider::query()->findOrFail($providerId);
                $station = LogisticsHub::query()->where('logistics_provider_id', $providerId)
                    ->where('hub_type', LogisticsHub::STATION)->findOrFail($data['station_hub_id']);
                if ($station->isRejected()) {
                    throw ValidationException::withMessages(['station_hub_id' => 'Rejected stations cannot receive coverage.']);
                }
                if ($provider->hasVerifiedProvince()
                    && (string) $station->province_code !== (string) $provider->province_code) {
                    throw ValidationException::withMessages([
                        'station_hub_id' => 'Coverage must be under a Station Hub inside your verified province.',
                    ]);
                }
                $parent = $station->parent;
                if ($parent === null || $parent->isArchived() || ! $parent->isLiveProvinceHub()
                    || (int) $parent->logistics_provider_id !== $providerId) {
                    throw ValidationException::withMessages([
                        'station_hub_id' => 'Coverage requires a Station Hub under your live Province Hub.',
                    ]);
                }
                try {
                    $city = collect($this->locations->getCitiesMunicipalities((string) $station->province_code, (string) $station->region_code))
                        ->firstWhere('code', $data['city_municipality_code']);
                    $barangay = collect($this->locations->getBarangays($data['city_municipality_code']))
                        ->firstWhere('code', $data['barangay_code']);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['barangay_code' => 'PSGC validation is unavailable. Try again later.']);
                }
                if (! $city) {
                    throw ValidationException::withMessages(['city_municipality_code' => 'The selected municipality does not belong to the Station Hub province.']);
                }
                if (! $barangay) {
                    throw ValidationException::withMessages(['barangay_code' => 'Select a valid PSGC barangay within the Station Hub province.']);
                }
                $area = $existing === null ? new Area : Area::query()->where('logistics_provider_id', $providerId)->findOrFail($existing->id);
                $active = (bool) ($data['is_active'] ?? ($area->exists ? $area->is_active : true));
                $changed = $area->exists && ((int) $area->station_hub_id !== (int) $station->id
                    || $area->barangay_code !== $data['barangay_code'] || ($area->is_active && ! $active));
                if ($changed) {
                    $this->assertCoverageRemovable($area);
                }
                if ($active && Area::query()->where('logistics_provider_id', $providerId)
                    ->where('barangay_code', $data['barangay_code'])->where('is_active', true)
                    ->when($area->exists, fn ($q) => $q->whereKeyNot($area->id))->exists()) {
                    throw ValidationException::withMessages(['barangay_code' => 'This barangay already has active coverage for your company.']);
                }
                $area->fill([
                    'logistics_provider_id' => $providerId,
                    'station_hub_id' => $station->id,
                    'name' => $data['name'],
                    'city_municipality_code' => $data['city_municipality_code'],
                    'municipality' => $city['name'],
                    'barangay_code' => $barangay['code'],
                    'barangay_name' => $barangay['name'],
                    'coverage_validated_at' => now(),
                    'is_active' => $active,
                ])->save();

                return $area;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barangay_code' => 'This barangay already has active coverage for your company.']);
        }
    }

    public function removeCoverage(int $providerId, Area $area): void
    {
        DB::transaction(function () use ($providerId, $area): void {
            $this->lockProvider($providerId);
            $area = Area::query()->where('logistics_provider_id', $providerId)->findOrFail($area->id);
            $this->assertCoverageRemovable($area);
            if (SellerOrder::query()->where('destination_area_id', $area->id)->exists()) {
                throw ValidationException::withMessages(['coverage' => 'Coverage referenced by parcel history cannot be deleted.']);
            }
            $area->delete();
        }, 3);
    }

    public function linkCourierToAreas(int $providerId, int $courierId, array $areaIds): void
    {
        if (! Schema::hasTable('area_courier')) {
            throw ValidationException::withMessages(['area_ids' => 'Apply the pending rider-barangay migration before linking riders to barangays. Existing links are unchanged.']);
        }
        DB::transaction(function () use ($providerId, $courierId, $areaIds): void {
            $this->lockProvider($providerId);
            $courier = Courier::query()->where('logistics_provider_id', $providerId)
                ->with('rider.station')->lockForUpdate()->findOrFail($courierId);
            if (! $courier->isEligibleForAssignment()) {
                throw ValidationException::withMessages(['courier_id' => 'Choose an approved, active rider in an operational Station Hub.']);
            }
            $areas = Area::query()->where('logistics_provider_id', $providerId)
                ->whereIn('id', $areaIds)->operationalCoverage()->lockForUpdate()->get();
            if ($areas->count() !== count(array_unique($areaIds))) {
                throw ValidationException::withMessages(['area_ids' => 'Select active barangays in your operational Station Hubs.']);
            }
            if ($areas->contains(fn (Area $area) => (int) $area->station_hub_id !== (int) $courier->rider->station_hub_id)) {
                throw ValidationException::withMessages(['courier_id' => 'This rider is assigned to a different Station Hub than the selected coverage.']);
            }
            $courier->areas()->syncWithoutDetaching($areas->modelKeys());
            // Retain the legacy primary-area pointer without replacing any of
            // the many-to-many links just added above.
            $courier->fill(['area_id' => $areas->first()->id])->saveQuietly();
        }, 3);
    }

    public function unlinkCourierFromArea(int $providerId, Area $area, Courier $courier): void
    {
        DB::transaction(function () use ($providerId, $area, $courier): void {
            $this->lockProvider($providerId);
            $courier = Courier::query()->where('logistics_provider_id', $providerId)->lockForUpdate()->findOrFail($courier->id);
            $area = Area::query()->where('logistics_provider_id', $providerId)->findOrFail($area->id);
            if (! Schema::hasTable('area_courier')) {
                if ((int) $courier->area_id === (int) $area->id) {
                    $courier->update(['area_id' => null]);
                }

                return;
            }
            $courier->areas()->detach($area->id);
            if ((int) $courier->area_id === (int) $area->id) {
                $courier->update(['area_id' => $courier->areas()->orderBy('areas.id')->value('areas.id')]);
            }
        }, 3);
    }

    private function assertCoverageRemovable(Area $area): void
    {
        if ($area->couriers()->exists() || ($area->station && $this->deactivationBlockReason($area->station) !== null)) {
            throw ValidationException::withMessages(['coverage' => 'Unlink riders and finish parcels before changing this coverage.']);
        }
        if ($area->is_active && $area->station?->is_active
            && ! $area->station->areas()->whereKeyNot($area->id)->where('is_active', true)
                ->whereNotNull('barangay_code')->whereNotNull('coverage_validated_at')->exists()) {
            throw ValidationException::withMessages(['coverage' => 'An active Station Hub must retain at least one valid barangay. Deactivate the station first.']);
        }
    }

    public function activate(LogisticsHub $hub, bool $active): void
    {
        DB::transaction(function () use ($hub, $active): void {
            $this->lockProvider($hub->logistics_provider_id);
            $hub = LogisticsHub::query()->findOrFail($hub->id);
            if ($active && ! $hub->isApproved()) {
                throw ValidationException::withMessages(['is_active' => 'Only approved hubs can be activated.']);
            }
            if ($active && $hub->hub_type === LogisticsHub::STATION) {
                if (! $this->coverageSchemaAvailable()) {
                    throw ValidationException::withMessages(['is_active' => 'Ask an administrator to apply the pending Barangay Coverage migrations before station activation.']);
                }
                if (! $hub->parent?->isOperational()) {
                    throw ValidationException::withMessages(['is_active' => 'Activate the parent Province Hub first.']);
                }
                $areas = $hub->areas()->where('logistics_provider_id', $hub->logistics_provider_id)
                    ->where('is_active', true)->whereNotNull('barangay_code')->whereNotNull('coverage_validated_at')->get();
                if ($areas->isEmpty()) {
                    throw ValidationException::withMessages(['is_active' => 'Configure at least one valid PSGC barangay before activating this station.']);
                }
                // Revalidate at activation; upstream failure must never activate guessed coverage.
                foreach ($areas as $area) {
                    $this->saveCoverage($hub->logistics_provider_id, $area->only(['name', 'station_hub_id', 'city_municipality_code', 'barangay_code', 'is_active']), $area);
                }
            }
            if (! $active && ($reason = $this->deactivationBlockReason($hub)) !== null) {
                throw ValidationException::withMessages(['is_active' => $reason]);
            }
            $hub->forceFill(['is_active' => $active])->save();
        }, 3);
    }

    public function areaForBarangay(int $providerId, ?string $code, bool $lock = false): ?Area
    {
        if (blank($code) || ! $this->coverageSchemaAvailable()) {
            return null;
        }
        $query = Area::query()->where('logistics_provider_id', $providerId)
            ->where('barangay_code', $code)->whereNotNull('coverage_validated_at')->where('is_active', true);
        $area = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($area === null) {
            return null;
        }
        // Lock each row explicitly: locking an outer query does not lock EXISTS
        // subqueries, and a plain read may reuse a pre-lock REPEATABLE READ snapshot.
        $stationQuery = LogisticsHub::query()->whereKey($area->station_hub_id)
            ->where('logistics_provider_id', $providerId)->where('hub_type', LogisticsHub::STATION)->operational();
        $station = ($lock ? $stationQuery->lockForUpdate() : $stationQuery)->first();
        if ($station === null) {
            return null;
        }
        $parentQuery = LogisticsHub::query()->whereKey($station->parent_hub_id)
            ->where('logistics_provider_id', $providerId)->where('hub_type', LogisticsHub::PROVINCE)->operational();
        $parent = ($lock ? $parentQuery->lockForUpdate() : $parentQuery)->first();

        return $parent === null ? null : $area;
    }

    /**
     * Does this provider hold validated, operational coverage for the parcel's
     * seller origin? Unclaimed parcels may only be listed/claimed by a provider
     * that can physically collect them. Missing origin codes fail closed.
     */
    public function servesOrigin(int|LogisticsProvider $provider, SellerOrder $parcel): bool
    {
        $providerId = $provider instanceof LogisticsProvider ? (int) $provider->getKey() : $provider;

        if ($parcel->carrier_provider_id !== null && (int) $parcel->carrier_provider_id !== $providerId) {
            return false;
        }

        $parcel->loadMissing('seller');

        return $this->areaForBarangay($providerId, $parcel->sellerBarangayCode()) !== null;
    }

    /**
     * Authoritative origin Station Hub for custody / scan authorization.
     *
     * Prefers the persisted `seller_orders.origin_station_hub_id`. Live PSGC
     * coverage is used only when assigning a new route (see
     * {@see assignOriginStationIfMissing}); coverage changes never redirect
     * parcels that already have a stored station.
     */
    public function originStationHubId(int $providerId, SellerOrder $parcel): ?int
    {
        $parcel->loadMissing(['originStation', 'seller', 'courier.rider']);

        if ($parcel->origin_station_hub_id !== null) {
            return $this->ownedStationHubId($providerId, (int) $parcel->origin_station_hub_id);
        }

        // A rider's current station cannot establish a parcel's historical origin.
        return null;
    }

    /**
     * Persist origin Station Hub from current seller-barangay coverage when
     * pickup routing is first established. Never overwrites an existing value.
     */
    public function assignOriginStationIfMissing(SellerOrder $parcel, int $providerId): ?int
    {
        if ($parcel->origin_station_hub_id !== null) {
            return $this->ownedStationHubId($providerId, (int) $parcel->origin_station_hub_id);
        }

        $parcel->loadMissing('seller');
        $area = $this->areaForBarangay($providerId, $parcel->sellerBarangayCode());
        $stationId = $area?->station_hub_id;

        if ($stationId === null) {
            return null;
        }

        // Compare-and-set also protects against callers holding a stale model.
        SellerOrder::query()->whereKey($parcel->id)->whereNull('origin_station_hub_id')
            ->update(['origin_station_hub_id' => (int) $stationId]);
        $parcel->refresh();

        return $this->ownedStationHubId($providerId, (int) $parcel->origin_station_hub_id);
    }

    /**
     * @throws ValidationException when origin Station Hub cannot be determined
     */
    public function requireOriginStationHubId(int $providerId, SellerOrder $parcel): int
    {
        $stationId = $this->originStationHubId($providerId, $parcel);

        if ($stationId === null) {
            throw ValidationException::withMessages([
                'station_hub' => 'This parcel has no authorized origin Station Hub. Resolve origin routing manually before confirming receipt or sorting.',
            ]);
        }

        return $stationId;
    }

    /** Sorting after a verified handoff uses the receiving network's current hub. */
    public function sortingHubId(int $providerId, SellerOrder $parcel): ?int
    {
        if ($parcel->custody_provider_id === null) {
            return $this->originStationHubId($providerId, $parcel);
        }

        if ($parcel->currentCustodyProviderId() !== $providerId) {
            return null;
        }

        $hubId = (new ManifestService)->currentHubId($parcel);

        return LogisticsHub::query()->whereKey($hubId)->where('logistics_provider_id', $providerId)
            ->operational()->exists() ? $hubId : null;
    }

    private function ownedStationHubId(int $providerId, int $stationHubId): ?int
    {
        $exists = LogisticsHub::query()
            ->whereKey($stationHubId)
            ->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::STATION)
            ->exists();

        return $exists ? $stationHubId : null;
    }

    private function assertLegacyVerificationAllowed(LogisticsProvider $provider, SellerOrder $parcel): void
    {
        Gate::forUser($provider)->authorize('remediate-shipment-address', $parcel);
        if (! in_array($parcel->status, [OrderStatus::AtSortingCenter, OrderStatus::Sorted], true)
            || $parcel->delivery_courier_id !== null || filled($parcel->order?->shipping_address['barangay_code'] ?? null)) {
            throw ValidationException::withMessages(['address' => 'Only an unassigned delivery at sorting with a missing snapshot barangay code can be verified.']);
        }
    }

    public function previewLegacyDeliveryAddress(LogisticsProvider $provider, SellerOrder $parcel): array
    {
        $this->assertLegacyVerificationAllowed($provider, $parcel);
        $address = $parcel->order?->shipping_address ?? [];
        $provinceName = $address['province'] ?? $address['province_name'] ?? '';
        $cityName = $address['municipality'] ?? $address['municipality_name'] ?? '';
        $barangayName = $address['barangay'] ?? $address['barangay_name'] ?? '';
        if (blank($provinceName) || blank($cityName) || blank($barangayName)) {
            throw ValidationException::withMessages(['address' => 'The original shipping snapshot must identify province, municipality and barangay. Obtain original address evidence; do not use a current profile or guess.']);
        }
        foreach ([['province', 'province_name'], ['municipality', 'municipality_name'], ['barangay', 'barangay_name']] as [$first, $second]) {
            if (filled($address[$first] ?? null) && filled($address[$second] ?? null)
                && Str::lower(trim($address[$first])) !== Str::lower(trim($address[$second]))) {
                throw ValidationException::withMessages(['address' => 'Original address fields conflict. Resolve the original evidence before verification.']);
            }
        }
        $match = function (array $items, string $name, string $field): array {
            $normalize = fn (string $value): string => Str::lower(preg_replace('/\s+/u', ' ', trim($value)));
            $matches = collect($items)->filter(fn ($item) => $normalize($item['name']) === $normalize($name));
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['address' => "Original {$field} evidence has no unique PSGC match. Resolve the evidence before verification."]);
            }

            return $matches->first();
        };
        try {
            $province = $match($this->locations->getProvinces($address['region_code'] ?? (Str::lower($provinceName) === 'metro manila' ? '130000000' : null)), $provinceName, 'province');
            $city = $match($this->locations->getCitiesMunicipalities($province['code'], $address['region_code'] ?? null), $cityName, 'municipality');
            $barangay = $match($this->locations->getBarangays($city['code']), $barangayName, 'barangay');
            foreach (['province_code' => $province['code'], 'municipality_code' => $city['code'], 'city_municipality_code' => $city['code']] as $field => $code) {
                if (filled($address[$field] ?? null) && $address[$field] !== $code) {
                    throw ValidationException::withMessages(['address' => 'Original address names and codes conflict. Resolve the evidence before verification.']);
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['address' => 'PSGC verification is unavailable. No address evidence has been changed.']);
        }

        return ['province_code' => $province['code'], 'province_name' => $province['name'],
            'city_municipality_code' => $city['code'], 'municipality_name' => $city['name'],
            'barangay_code' => $barangay['code'], 'barangay_name' => $barangay['name'],
            'snapshot_hash' => $parcel->shippingAddressFingerprint()];
    }

    public function confirmLegacyDeliveryAddress(LogisticsProvider $provider, SellerOrder $parcel, string $fingerprint, string $barangayCode, string $evidence): SellerOrder
    {
        if (! Schema::hasColumn('seller_orders', 'delivery_address_verification') || ! $this->coverageSchemaAvailable()) {
            throw ValidationException::withMessages(['address' => 'Ask an administrator to apply the pending Barangay Coverage migrations before verification.']);
        }
        if (blank($evidence) || mb_strlen($evidence) > 1000) {
            throw ValidationException::withMessages(['evidence' => 'Record the original address evidence reviewed (maximum 1000 characters).']);
        }

        return DB::transaction(function () use ($provider, $parcel, $fingerprint, $barangayCode, $evidence): SellerOrder {
            $this->lockProvider($provider->id);
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            // Lock the snapshot itself so a concurrent edit cannot invalidate a confirmation.
            $parcel->setRelation('order', $parcel->order()->lockForUpdate()->firstOrFail());
            $this->assertLegacyVerificationAllowed($provider, $parcel);
            if (! hash_equals($parcel->shippingAddressFingerprint(), $fingerprint)) {
                throw ValidationException::withMessages(['address' => 'The original snapshot changed. Preview and review the evidence again.']);
            }
            if ($parcel->delivery_address_verification !== null) {
                throw ValidationException::withMessages(['address' => 'This parcel already has verification evidence. Existing evidence cannot be overwritten.']);
            }
            $resolved = $this->previewLegacyDeliveryAddress($provider, $parcel);
            if ($resolved['barangay_code'] !== $barangayCode) {
                throw ValidationException::withMessages(['address' => 'The confirmed code does not match the original snapshot evidence.']);
            }
            $area = $this->areaForBarangay($provider->id, $barangayCode, lock: true);
            if ($area === null) {
                throw ValidationException::withMessages(['address' => 'Configure validated coverage in an approved active Station Hub for this barangay, then confirm verification.']);
            }
            if ($parcel->destination_area_id !== null && (int) $parcel->destination_area_id !== (int) $area->id) {
                $legacy = Area::query()->whereKey($parcel->destination_area_id)->lockForUpdate()->first();
                if ($legacy === null || (int) $legacy->logistics_provider_id !== (int) $provider->id
                    || filled($legacy->barangay_code) || (int) $legacy->station_hub_id !== (int) $area->station_hub_id
                    || (filled($legacy->city_municipality_code) && $legacy->city_municipality_code !== $resolved['city_municipality_code'])) {
                    throw ValidationException::withMessages(['address' => 'The existing destination area conflicts with verified coverage. Review the station handoff before remediation.']);
                }
            }
            $parcel->forceFill(['delivery_address_verification' => $resolved + [
                'verified_by_provider_id' => $provider->id, 'verified_at' => now()->toIso8601String(),
                'evidence' => trim($evidence), 'original_destination_area_id' => $parcel->destination_area_id,
            ], 'destination_area_id' => $area->id])->save();
            OperationsAuditLog::record($provider, 'address_verified_manually', $parcel, 'Parcel #'.$parcel->id,
                ['barangay' => null], ['barangay' => $resolved['barangay_name']], $evidence);

            return $parcel;
        }, 3);
    }

    /**
     * A destination provider qualifies for a cross-provider partnership only
     * when it can actually receive parcels in its verified province: at least
     * one validated, active barangay sits under an approved active Station Hub
     * whose live Province Hub belongs to the same provider and province.
     */
    public function hasOperationalProvinceCoverage(LogisticsProvider $provider): bool
    {
        if (! $provider->hasVerifiedProvince() || ! $this->coverageSchemaAvailable()) {
            return false;
        }

        return Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->operationalCoverage()
            ->whereHas('station', fn (Builder $station) => $station
                ->where('logistics_provider_id', $provider->id)
                ->where('province_code', $provider->province_code)
                ->whereNull('archived_at')
                ->whereHas('parent', fn (Builder $parent) => $parent
                    ->where('logistics_provider_id', $provider->id)
                    ->where('hub_type', LogisticsHub::PROVINCE)
                    ->where('active_province_slot', 1)
                    ->whereNull('archived_at')
                    ->where('province_code', $provider->province_code)))
            ->exists();
    }

    /** @return array{items: list<array{key: string, label: string, complete: bool, count: int}>, complete: bool} */
    public function checklist(int $providerId): array
    {
        $provinceCount = LogisticsHub::query()->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->count();
        $stationCount = LogisticsHub::query()->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->count();
        $coverageCount = ! $this->coverageSchemaAvailable() ? 0 : Area::query()->where('logistics_provider_id', $providerId)
            ->whereNotNull('station_hub_id')->whereNotNull('barangay_code')->whereNotNull('coverage_validated_at')->where('is_active', true)
            ->whereHas('station', fn ($query) => $query->where('approval_status', LogisticsHub::APPROVAL_APPROVED)->where('is_active', true))
            ->count();
        $approvedRiderCount = Rider::query()->where('logistics_provider_id', $providerId)
            ->eligibleForAssignment()
            ->whereNotNull('station_hub_id')
            ->whereHas('station', fn ($query) => $query
                ->where('logistics_provider_id', $providerId)
                ->where('hub_type', LogisticsHub::STATION)
                ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                ->where('is_active', true))
            ->count();

        $items = [
            ['key' => 'province_hub', 'label' => 'Create Approved Province Hub', 'complete' => $provinceCount > 0, 'count' => $provinceCount],
            ['key' => 'station_hub', 'label' => 'Create Approved Station Hub', 'complete' => $stationCount > 0, 'count' => $stationCount],
            ['key' => 'coverage', 'label' => 'Configure Barangay Coverage', 'complete' => $coverageCount > 0, 'count' => $coverageCount],
            ['key' => 'approved_rider', 'label' => 'Approve First Rider', 'complete' => $approvedRiderCount > 0, 'count' => $approvedRiderCount],
        ];

        return ['items' => $items, 'complete' => collect($items)->every(fn (array $item) => $item['complete'])];
    }

    public function assignmentBlockReason(int $providerId): ?string
    {
        if (! $this->coverageSchemaAvailable()) {
            return 'Ask an administrator to apply the pending Barangay Coverage migrations. New assignments are blocked; existing parcels can continue.';
        }
        $incomplete = collect($this->checklist($providerId)['items'])->first(fn (array $item) => ! $item['complete']);

        return $incomplete === null
            ? null
            : 'Complete network setup before assigning parcels. First, '.$incomplete['label'].'.';
    }

    public function deactivationBlockReason(LogisticsHub $hub): ?string
    {
        if ($hub->hub_type === LogisticsHub::PROVINCE && $hub->stations()->where('is_active', true)->exists()) {
            return 'Deactivate this province hub\'s stations before deactivating the province hub.';
        }

        $stationIds = $hub->hub_type === LogisticsHub::STATION
            ? [$hub->id]
            : $hub->stations()->pluck('id')->all();
        $areaIds = Area::query()->whereIn('station_hub_id', $stationIds)->pluck('id');
        $courierIds = Courier::query()->where(function ($q) use ($areaIds, $stationIds): void {
            $q->whereHas('areas', fn ($areas) => $areas->whereIn('areas.id', $areaIds))
                ->orWhereHas('rider', fn ($r) => $r->whereIn('station_hub_id', $stationIds));
        })->pluck('id');

        if ($courierIds->isNotEmpty() && Courier::query()->whereIn('id', $courierIds)->where('is_available', false)->exists()) {
            return 'This hub has riders assigned to active work and cannot be deactivated yet.';
        }

        $openStatuses = array_diff(array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()), [
            OrderStatus::Delivered->value,
            OrderStatus::Completed->value,
            OrderStatus::Returned->value,
        ]);

        $hasOpenOrders = SellerOrder::query()
            ->whereIn('status', $openStatuses)
            ->where(function ($query) use ($areaIds, $courierIds): void {
                $query->whereIn('destination_area_id', $areaIds);
                if ($courierIds->isNotEmpty()) {
                    $query->orWhereIn('courier_id', $courierIds)
                        ->orWhereIn('delivery_courier_id', $courierIds);
                }
            })
            ->exists();

        return $hasOpenOrders
            ? 'This hub has parcels in progress and cannot be deactivated yet.'
            : null;
    }

    /** @return Collection<int, int> */
    public function stationIdsForFacility(LogisticsProvider $provider, ?LogisticsHub $facility): Collection
    {
        if ($facility === null) {
            return LogisticsHub::query()->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::STATION)
                ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                ->where('is_active', true)
                ->pluck('id');
        }

        if ((int) $facility->logistics_provider_id !== (int) $provider->id) {
            abort(404);
        }

        if ($facility->hub_type === LogisticsHub::STATION) {
            return $facility->isOperational() ? collect([$facility->id]) : collect();
        }

        return $facility->stations()
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->pluck('id');
    }
}
