<?php

namespace App\Services;

use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\SellerOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provider province ownership: verify/lock at Admin approval, provision the
 * single non-archived Province Hub, and gate unsafe Admin province changes.
 */
class ProviderProvinceService
{
    public function __construct(
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly PhilippineLocationService $locations = new PhilippineLocationService,
    ) {}

    public function isProvinceLocked(LogisticsProvider $provider): bool
    {
        return $provider->province_verified_at !== null && filled($provider->province_code);
    }

    /**
     * Stamp province verification when Admin approves a pending registration.
     *
     * @throws ValidationException when province is missing
     */
    public function verifyProvinceOnApproval(LogisticsProvider $provider, Admin $admin): void
    {
        if (blank($provider->province_code)) {
            throw ValidationException::withMessages([
                'province_code' => 'This logistics registration has no province. Reject it or ask the applicant to re-register with a province.',
            ]);
        }

        $provider->forceFill([
            'province_verified_at' => now(),
            'province_verified_by' => $admin->id,
        ])->save();
    }

    /**
     * @throws HttpException
     */
    public function assertLogisticsCannotChangeProvince(LogisticsProvider $provider, ?string $nextProvinceCode): void
    {
        if (! $this->isProvinceLocked($provider)) {
            return;
        }

        if ($nextProvinceCode === null || (string) $nextProvinceCode === (string) $provider->province_code) {
            return;
        }

        abort(403, 'Your service province is locked. Contact an administrator to change it.');
    }

    /**
     * Admin-only province change. Blocks when stations, coverage, riders, or
     * active parcels still depend on the current province.
     *
     * @return list<string> blockers (empty when the change is allowed)
     */
    public function provinceChangeBlockers(LogisticsProvider $provider): array
    {
        $blockers = [];

        $stations = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->whereNull('archived_at')
            ->count();
        if ($stations > 0) {
            $blockers[] = "{$stations} Station Hub(s) must be archived or removed first.";
        }

        if ($this->hubs->coverageSchemaAvailable()) {
            $coverage = Area::query()
                ->where('logistics_provider_id', $provider->id)
                ->where('is_active', true)
                ->count();
            if ($coverage > 0) {
                $blockers[] = "{$coverage} active barangay coverage row(s) must be deactivated first.";
            }
        }

        $riders = Rider::query()
            ->where('logistics_provider_id', $provider->id)
            ->whereNotNull('station_hub_id')
            ->count();
        if ($riders > 0) {
            $blockers[] = "{$riders} rider(s) are still assigned to a Station Hub.";
        }

        $activeParcels = SellerOrder::query()
            ->where(function ($q) use ($provider): void {
                $q->whereHas('courier', fn ($c) => $c->where('logistics_provider_id', $provider->id))
                    ->orWhereHas('deliveryCourier', fn ($c) => $c->where('logistics_provider_id', $provider->id));
            })
            ->whereNotIn('status', ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'])
            ->count();
        if ($activeParcels > 0) {
            $blockers[] = "{$activeParcels} active parcel(s) still reference this provider's fleet.";
        }

        return $blockers;
    }

    /**
     * @throws ValidationException
     */
    public function changeProvinceByAdmin(
        LogisticsProvider $provider,
        Admin $admin,
        string $provinceCode,
        ?string $provinceName = null,
        ?string $regionCode = null,
        ?string $regionName = null,
    ): LogisticsProvider {
        $blockers = $this->provinceChangeBlockers($provider);
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'province_code' => 'Cannot change province until these are resolved: '.implode(' ', $blockers),
            ]);
        }

        $resolved = $this->resolveProvince($provinceCode, $regionCode, $provinceName, $regionName);

        return DB::transaction(function () use ($provider, $admin, $resolved): LogisticsProvider {
            $this->hubs->lockProvider((int) $provider->id);
            $provider = LogisticsProvider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            // Archive the live Province Hub so a new one can be provisioned.
            LogisticsHub::query()
                ->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::PROVINCE)
                ->whereNull('archived_at')
                ->where('active_province_slot', 1)
                ->lockForUpdate()
                ->get()
                ->each(function (LogisticsHub $hub): void {
                    $hub->forceFill([
                        'archived_at' => now(),
                        'active_province_slot' => null,
                        'is_active' => false,
                    ])->save();
                });

            app()->instance('admin.changing_provider_province', true);
            try {
                $provider->forceFill([
                    'province_code' => $resolved['province_code'],
                    'province_name' => $resolved['province_name'],
                    'province_verified_at' => now(),
                    'province_verified_by' => $admin->id,
                ])->save();
            } finally {
                app()->forgetInstance('admin.changing_provider_province');
            }

            return $provider->fresh();
        }, 3);
    }

    /**
     * Provision the single non-archived Province Hub for an approved provider.
     *
     * @throws ValidationException
     */
    public function provisionProvinceHub(
        LogisticsProvider $provider,
        Admin $admin,
        array $address,
    ): LogisticsHub {
        if (! $provider->isApproved()) {
            throw ValidationException::withMessages([
                'provider' => 'Only approved logistics providers can receive a Province Hub.',
            ]);
        }

        if (! $this->isProvinceLocked($provider)) {
            throw ValidationException::withMessages([
                'province_code' => 'Verify the provider province before provisioning a Province Hub.',
            ]);
        }

        // Force the verified province — never trust a client-submitted province_code.
        $resolved = $this->locations->validateAndResolve([
            'region_code' => (string) ($address['region_code'] ?? ''),
            'province_code' => (string) $provider->province_code,
            'city_municipality_code' => (string) ($address['city_municipality_code'] ?? ''),
            'barangay_code' => (string) ($address['barangay_code'] ?? ''),
            'region_name' => $address['region_name'] ?? null,
            'province_name' => $provider->province_name,
            'municipality_name' => $address['municipality_name'] ?? null,
            'barangay_name' => $address['barangay_name'] ?? null,
        ]);

        try {
            return DB::transaction(function () use ($provider, $admin, $address, $resolved): LogisticsHub {
                $this->hubs->lockProvider((int) $provider->id);

                $existing = LogisticsHub::query()
                    ->where('logistics_provider_id', $provider->id)
                    ->where('hub_type', LogisticsHub::PROVINCE)
                    ->where('active_province_slot', 1)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'hub' => 'This provider already has a Province Hub. Archive it before provisioning another.',
                    ]);
                }

                $hub = new LogisticsHub;
                $hub->forceFill([
                    'logistics_provider_id' => $provider->id,
                    'parent_hub_id' => null,
                    'name' => $address['name'] ?? ($resolved['province_name'].' Province Hub'),
                    'hub_type' => LogisticsHub::PROVINCE,
                    'region_code' => (string) ($address['region_code'] ?? ''),
                    'region_name' => $resolved['region_name'],
                    'province_code' => $provider->province_code,
                    'province_name' => $resolved['province_name'] ?: $provider->province_name,
                    'city_municipality_code' => $address['city_municipality_code'],
                    'municipality' => $resolved['municipality_name'],
                    'barangay_code' => $address['barangay_code'],
                    'barangay_name' => $resolved['barangay_name'],
                    'street' => $address['street'] ?? null,
                    'building' => $address['building'] ?? null,
                    'landmark' => $address['landmark'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'location_details' => $address['location_details'] ?? null,
                    'is_active' => true,
                    'approval_status' => LogisticsHub::APPROVAL_APPROVED,
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                    'active_province_slot' => 1,
                    'archived_at' => null,
                ])->save();

                return $hub->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'hub' => 'This provider already has a Province Hub. Archive it before provisioning another.',
            ]);
        }
    }

    public function activeProvinceHub(LogisticsProvider $provider): ?LogisticsHub
    {
        return LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->where('active_province_slot', 1)
            ->whereNull('archived_at')
            ->first();
    }

    /**
     * @return array{province_code: string, province_name: string, region_code: ?string, region_name: ?string}
     */
    private function resolveProvince(
        string $provinceCode,
        ?string $regionCode,
        ?string $provinceName,
        ?string $regionName,
    ): array {
        try {
            $provinces = $this->locations->getProvinces($regionCode);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'province_code' => 'PSGC province lookup is unavailable. Try again later.',
            ]);
        }

        $match = collect($provinces)->firstWhere('code', $provinceCode);
        if ($match === null && $regionCode === null) {
            // Search all provinces when region omitted.
            try {
                $match = collect($this->locations->getProvinces(null))->firstWhere('code', $provinceCode);
            } catch (\Throwable) {
                $match = null;
            }
        }

        if ($match === null && $provinceCode === '130000000') {
            $match = ['code' => '130000000', 'name' => 'Metro Manila'];
        }

        if ($match === null) {
            throw ValidationException::withMessages([
                'province_code' => 'Select a valid PSGC province.',
            ]);
        }

        return [
            'province_code' => (string) $match['code'],
            'province_name' => (string) ($match['name'] ?: $provinceName),
            'region_code' => $regionCode,
            'region_name' => $regionName,
        ];
    }
}
