<?php

namespace Database\Seeders;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Seller\SellerShippingProviderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Idempotent, account-discovery seeder for Logistics flow testing.
 *
 * Discovers approved Buyers / Sellers / Logistics providers / Riders already in
 * the database and only fills missing hubs, barangay coverage, seller shipping
 * opt-ins, rider area links, and bilateral partnerships — without changing
 * passwords, emails, roles, registered provinces, orders, payments, or stock.
 *
 * Defaults to dry-run. Writes only when LOGISTICS_FLOW_SEED_APPLY=1.
 *
 *   php artisan db:seed --class=LogisticsFlowTestSeeder
 *   set LOGISTICS_FLOW_SEED_APPLY=1
 *   php artisan db:seed --class=LogisticsFlowTestSeeder
 */
class LogisticsFlowTestSeeder extends Seeder
{
    /** @var list<array{type: string, detail: string, meta?: array<string, mixed>}> */
    private array $actions = [];

    /** @var list<string> */
    private array $blockers = [];

    /** @var list<string> */
    private array $notes = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('LogisticsFlowTestSeeder refuses to run in production.');

            return;
        }

        $plan = $this->buildPlan();
        $this->printReport($plan);

        if (! $this->shouldApply()) {
            $this->command?->warn('Dry-run only — no database writes. Set LOGISTICS_FLOW_SEED_APPLY=1 to apply.');

            return;
        }

        $this->applyPlan($plan);
        $this->command?->info('LogisticsFlowTestSeeder applied.');
    }

    /**
     * Build the discovery + action plan without writing.
     *
     * @return array<string, mixed>
     */
    public function buildPlan(): array
    {
        $this->actions = [];
        $this->blockers = [];
        $this->notes = [];

        $buyers = $this->eligibleBuyers();
        $sellers = $this->eligibleSellers();
        $providers = $this->eligibleProviders();
        $riders = $this->eligibleRiders();

        $verifiedProviders = $providers->filter(fn (LogisticsProvider $p): bool => $p->hasVerifiedProvince());

        if ($verifiedProviders->isEmpty()) {
            $this->blockers[] = 'No approved Logistics provider has a verified province — cannot seed hubs/coverage.';
        }

        foreach ($verifiedProviders as $provider) {
            $this->planProviderInfrastructure($provider, $sellers, $buyers, $riders);
        }

        $this->planPartnerships($verifiedProviders);
        $this->planScenarios($verifiedProviders, $sellers, $buyers, $riders);

        $incompleteBuyers = Buyer::query()->where('status', 'approved')->where(function ($q): void {
            $q->whereNull('province_code')->orWhereNull('barangay_code')->orWhereNull('municipality_code');
        })->pluck('id');
        $incompleteSellers = Seller::query()->where('status', 'approved')->whereNull('archived_at')->where(function ($q): void {
            $q->whereNull('province_code')->orWhereNull('barangay_code')->orWhereNull('municipality_code');
        })->pluck('id');

        if ($incompleteBuyers->isNotEmpty()) {
            $this->notes[] = 'Approved buyers missing complete PSGC codes (skipped): '.$incompleteBuyers->implode(', ');
        }
        if ($incompleteSellers->isNotEmpty()) {
            $this->notes[] = 'Approved sellers missing complete PSGC codes (skipped): '.$incompleteSellers->implode(', ');
        }

        $unverifiedProviders = $providers->reject(fn (LogisticsProvider $p): bool => $p->hasVerifiedProvince());
        foreach ($unverifiedProviders as $provider) {
            $this->notes[] = "Provider #{$provider->id} ({$provider->business_name}) is approved but has no verified province — left unchanged.";
        }

        $nonAssignable = Rider::query()
            ->whereNotIn('id', $riders->pluck('id'))
            ->get(['id', 'email', 'status', 'station_hub_id']);
        foreach ($nonAssignable as $rider) {
            $this->notes[] = "Rider #{$rider->id} ({$rider->email}) status={$rider->status} station=".($rider->station_hub_id ?? 'none').' — not assignable; left unchanged (no fabricated approval).';
        }

        return [
            'counts' => [
                'buyers' => $buyers->count(),
                'sellers' => $sellers->count(),
                'providers' => $providers->count(),
                'verified_providers' => $verifiedProviders->count(),
                'riders' => $riders->count(),
            ],
            'participants' => [
                'buyers' => $buyers->map(fn (Buyer $b): array => $this->partySummary($b))->all(),
                'sellers' => $sellers->map(fn (Seller $s): array => $this->partySummary($s))->all(),
                'providers' => $providers->map(fn (LogisticsProvider $p): array => [
                    'id' => $p->id,
                    'business_name' => $p->business_name,
                    'province_code' => $p->province_code,
                    'province_name' => $p->province_name,
                    'province_verified' => $p->hasVerifiedProvince(),
                ])->all(),
                'riders' => $riders->map(fn (Rider $r): array => [
                    'id' => $r->id,
                    'email' => $r->email,
                    'status' => $r->status,
                    'provider_id' => $r->logistics_provider_id,
                    'station_hub_id' => $r->station_hub_id,
                    'courier_id' => $r->courier?->id,
                    'area_id' => $r->courier?->area_id,
                ])->all(),
            ],
            'actions' => $this->actions,
            'blockers' => $this->blockers,
            'notes' => $this->notes,
            'scenarios' => $this->scenarioSummary,
        ];
    }

    /** @var array<string, mixed> */
    private array $scenarioSummary = [];

    /**
     * @param  Collection<int, LogisticsProvider>  $verifiedProviders
     * @param  Collection<int, Seller>  $sellers
     * @param  Collection<int, Buyer>  $buyers
     * @param  Collection<int, Rider>  $riders
     */
    private function planProviderInfrastructure(
        LogisticsProvider $provider,
        Collection $sellers,
        Collection $buyers,
        Collection $riders,
    ): void {
        $provinceCode = (string) $provider->province_code;

        $provinceHub = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->liveProvince()
            ->first();

        if ($provinceHub === null) {
            $this->queue('create_province_hub', "Create live Province Hub for provider #{$provider->id} in {$provider->province_name}", [
                'provider_id' => $provider->id,
                'province_code' => $provinceCode,
                'province_name' => $provider->province_name,
            ]);
        } elseif (! $provinceHub->isOperational()) {
            $this->blockers[] = "Provider #{$provider->id} Province Hub #{$provinceHub->id} exists but is not operational (approved+active).";
        }

        $station = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('province_code', $provinceCode)
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();

        if ($station === null) {
            $anchor = $sellers->first(fn (Seller $s): bool => (string) $s->province_code === $provinceCode)
                ?? $buyers->first(fn (Buyer $b): bool => (string) $b->province_code === $provinceCode);

            if ($anchor === null) {
                $this->blockers[] = "Provider #{$provider->id} needs a Station Hub in {$provider->province_name}, but no seller/buyer address is available to anchor municipality/barangay.";
            } else {
                $this->queue('create_station_hub', "Create approved Station Hub for provider #{$provider->id} anchored at {$anchor->municipality_name} / {$anchor->barangay_name}", [
                    'provider_id' => $provider->id,
                    'province_code' => $provinceCode,
                    'city_municipality_code' => $anchor->municipality_code,
                    'municipality' => $anchor->municipality_name,
                    'barangay_code' => $anchor->barangay_code,
                    'barangay_name' => $anchor->barangay_name,
                ]);
            }
        }

        $provinceSellers = $sellers->filter(fn (Seller $s): bool => (string) $s->province_code === $provinceCode);
        $provinceBuyers = $buyers->filter(fn (Buyer $b): bool => (string) $b->province_code === $provinceCode);

        $neededBarangays = collect();
        foreach ($provinceSellers as $seller) {
            $neededBarangays[$seller->barangay_code] = [
                'barangay_code' => $seller->barangay_code,
                'barangay_name' => $seller->barangay_name,
                'city_municipality_code' => $seller->municipality_code,
                'municipality' => $seller->municipality_name,
                'reason' => "seller #{$seller->id} origin",
            ];
        }
        foreach ($provinceBuyers as $buyer) {
            $neededBarangays[$buyer->barangay_code] = [
                'barangay_code' => $buyer->barangay_code,
                'barangay_name' => $buyer->barangay_name,
                'city_municipality_code' => $buyer->municipality_code,
                'municipality' => $buyer->municipality_name,
                'reason' => "buyer #{$buyer->id} destination",
            ];
        }

        $hubs = app(LogisticsHubService::class);
        foreach ($neededBarangays as $row) {
            $existing = $hubs->areaForBarangay((int) $provider->id, (string) $row['barangay_code']);
            if ($existing !== null) {
                $this->notes[] = "Coverage already operational for {$row['barangay_name']} ({$row['barangay_code']}) under provider #{$provider->id}.";

                continue;
            }

            $this->queue('create_coverage', "Create validated barangay coverage {$row['barangay_name']} ({$row['barangay_code']}) for {$row['reason']}", [
                'provider_id' => $provider->id,
                'barangay_code' => $row['barangay_code'],
                'barangay_name' => $row['barangay_name'],
                'city_municipality_code' => $row['city_municipality_code'],
                'municipality' => $row['municipality'],
            ]);
        }

        foreach ($provinceSellers as $seller) {
            $enabled = SellerShippingProvider::query()
                ->where('seller_id', $seller->id)
                ->where('logistics_provider_id', $provider->id)
                ->where('is_enabled', true)
                ->exists();

            if ($enabled) {
                $this->notes[] = "Seller #{$seller->id} already enabled provider #{$provider->id}.";

                continue;
            }

            $this->queue('enable_shipping', "Enable seller #{$seller->id} shipping with provider #{$provider->id}", [
                'seller_id' => $seller->id,
                'provider_id' => $provider->id,
            ]);
        }

        $providerRiders = $riders->filter(fn (Rider $r): bool => (int) $r->logistics_provider_id === (int) $provider->id);
        foreach ($providerRiders as $rider) {
            $courier = $rider->courier;
            if ($courier === null) {
                $this->blockers[] = "Rider #{$rider->id} is assignable but has no Courier fleet row.";

                continue;
            }

            $area = $courier->area_id !== null ? Area::query()->find($courier->area_id) : null;
            $areaOk = $area !== null
                && $area->is_active
                && filled($area->barangay_code)
                && $area->coverage_validated_at !== null
                && (int) $area->station_hub_id === (int) $rider->station_hub_id;

            // Prefer Bayog (and other seller/buyer barangays) so checkout destinations
            // have an assignable rider on the matching validated coverage.
            $preferredBarangays = $neededBarangays->keys()->values()->all();

            $alreadyPreferred = $areaOk
                && $area !== null
                && in_array((string) $area->barangay_code, array_map('strval', $preferredBarangays), true);

            if ($alreadyPreferred) {
                $this->notes[] = "Rider #{$rider->id} courier already linked to preferred validated coverage #{$area->id} ({$area->barangay_name}).";

                continue;
            }

            $label = $rider->first_name.' '.$rider->last_name;
            $this->queue('assign_rider_area', "Link rider #{$rider->id} ({$label}) to preferred validated coverage under station #{$rider->station_hub_id}", [
                'rider_id' => $rider->id,
                'courier_id' => $courier->id,
                'provider_id' => $provider->id,
                'station_hub_id' => $rider->station_hub_id,
                'preferred_barangay_codes' => $preferredBarangays,
            ]);
        }
    }

    /**
     * @param  Collection<int, LogisticsProvider>  $verifiedProviders
     */
    private function planPartnerships(Collection $verifiedProviders): void
    {
        if ($verifiedProviders->count() < 2) {
            $this->blockers[] = 'Cross-province partnerships need ≥2 verified providers in different provinces. Currently '.$verifiedProviders->count().' verified provider(s).';

            return;
        }

        $byProvince = $verifiedProviders->groupBy(fn (LogisticsProvider $p): string => (string) $p->province_code);
        if ($byProvince->count() < 2) {
            $this->blockers[] = 'All verified providers share one province — cross-province A↔B links are not eligible.';

            return;
        }

        $admin = Admin::query()->orderBy('id')->first();
        if ($admin === null) {
            $this->blockers[] = 'No Admin account exists to approve partnerships through the real approval service.';

            return;
        }

        $hubs = app(LogisticsHubService::class);
        $pairs = [];
        $list = $verifiedProviders->values();
        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if ((string) $a->province_code === (string) $b->province_code) {
                    continue;
                }
                if (! $hubs->hasOperationalProvinceCoverage($a) || ! $hubs->hasOperationalProvinceCoverage($b)) {
                    $this->notes[] = "Skip partnership #{$a->id}↔#{$b->id} until both have operational province coverage (will exist after coverage actions).";
                }
                $pairs[] = [$a, $b];
            }
        }

        foreach ($pairs as [$origin, $destination]) {
            foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
                $existing = LogisticsProviderLink::query()
                    ->where('origin_provider_id', $from->id)
                    ->where('destination_provider_id', $to->id)
                    ->whereIn('status', [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE])
                    ->first();

                if ($existing?->isActive()) {
                    $this->notes[] = "Active partnership already exists #{$from->id}→#{$to->id} (link #{$existing->id}).";

                    continue;
                }

                $this->queue('ensure_partnership', "Ensure A→B partnership #{$from->id}→#{$to->id} via request + destination consent + Admin #{$admin->id} approve", [
                    'origin_provider_id' => $from->id,
                    'destination_provider_id' => $to->id,
                    'admin_id' => $admin->id,
                    'existing_link_id' => $existing?->id,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, LogisticsProvider>  $verifiedProviders
     * @param  Collection<int, Seller>  $sellers
     * @param  Collection<int, Buyer>  $buyers
     * @param  Collection<int, Rider>  $riders
     */
    private function planScenarios(Collection $verifiedProviders, Collection $sellers, Collection $buyers, Collection $riders): void
    {
        $sameProvince = [];
        foreach ($verifiedProviders as $provider) {
            $code = (string) $provider->province_code;
            $localSellers = $sellers->filter(fn (Seller $s): bool => (string) $s->province_code === $code);
            $localBuyers = $buyers->filter(fn (Buyer $b): bool => (string) $b->province_code === $code);
            $localRiders = $riders->filter(fn (Rider $r): bool => (int) $r->logistics_provider_id === (int) $provider->id);

            if ($localSellers->isNotEmpty() && $localBuyers->isNotEmpty() && $localRiders->isNotEmpty()) {
                $sameProvince[] = [
                    'provider_id' => $provider->id,
                    'province' => $provider->province_name,
                    'seller_ids' => $localSellers->pluck('id')->all(),
                    'buyer_ids' => $localBuyers->pluck('id')->all(),
                    'rider_ids' => $localRiders->pluck('id')->all(),
                ];
            }
        }

        $crossEligible = $verifiedProviders->groupBy(fn (LogisticsProvider $p): string => (string) $p->province_code)->count() >= 2
            && $verifiedProviders->count() >= 2;

        $this->scenarioSummary = [
            'same_province_delivery' => $sameProvince !== []
                ? ['possible_after_seed' => true, 'routes' => $sameProvince]
                : ['possible_after_seed' => false, 'reason' => 'Need seller + buyer + assignable rider in the same verified provider province.'],
            'cross_province_delivery' => $crossEligible
                ? ['possible_after_seed' => true, 'note' => 'Requires A↔B partnerships applied and operational coverage on both sides.']
                : ['possible_after_seed' => false, 'reason' => 'Need ≥2 verified providers in different provinces with operational coverage and Admin-approved links.'],
            'two_failed_attempts_then_return' => $sameProvince !== []
                ? ['possible_after_seed' => true, 'note' => 'Uses same-province path; return is a runtime flow (failed delivery attempts). Seeder does not fabricate QR custody or delivery attempts.']
                : ['possible_after_seed' => false, 'reason' => 'Blocked until same-province delivery path exists.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function applyPlan(array $plan): void
    {
        $hubs = app(LogisticsHubService::class);
        $shipping = app(SellerShippingProviderService::class);
        $partnerships = app(ProviderHandoffSchemaService::class);

        DB::transaction(function () use ($plan, $hubs, $shipping, $partnerships): void {
            foreach ($plan['actions'] as $action) {
                $meta = $action['meta'] ?? [];

                match ($action['type']) {
                    'create_province_hub' => $this->applyProvinceHub($meta),
                    'create_station_hub' => $this->applyStationHub($meta),
                    'create_coverage' => $this->applyCoverage($hubs, $meta),
                    'enable_shipping' => $this->applyShipping($shipping, $meta),
                    'assign_rider_area' => $this->applyRiderArea($meta),
                    'ensure_partnership' => $this->applyPartnership($partnerships, $meta),
                    default => $this->command?->warn('Unknown action '.$action['type']),
                };
            }
        }, 3);
    }

    /** @param  array<string, mixed>  $meta */
    private function applyProvinceHub(array $meta): void
    {
        $provider = LogisticsProvider::query()->findOrFail($meta['provider_id']);
        $existing = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->liveProvince()
            ->first();
        if ($existing !== null) {
            return;
        }

        $template = LogisticsHub::query()
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->where('province_code', $meta['province_code'])
            ->whereNotNull('region_code')
            ->orderBy('id')
            ->first();

        LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => ($provider->province_name ?: 'Province').' Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => $template?->region_code ?: substr((string) $meta['province_code'], 0, 2).'0000000',
            'region_name' => $template?->region_name,
            'province_code' => $meta['province_code'],
            'province_name' => $meta['province_name'],
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
            'active_province_slot' => 1,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function applyStationHub(array $meta): void
    {
        $provider = LogisticsProvider::query()->findOrFail($meta['provider_id']);
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->liveProvince()
            ->firstOrFail();

        $existing = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('province_code', $meta['province_code'])
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->first();
        if ($existing !== null) {
            return;
        }

        LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => ($meta['municipality'] ?: 'Flow').' Station Hub',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'region_name' => $province->region_name,
            'province_code' => $meta['province_code'],
            'province_name' => $province->province_name,
            'city_municipality_code' => $meta['city_municipality_code'],
            'municipality' => $meta['municipality'],
            'barangay_code' => $meta['barangay_code'],
            'barangay_name' => $meta['barangay_name'],
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function applyCoverage(LogisticsHubService $hubs, array $meta): void
    {
        if ($hubs->areaForBarangay((int) $meta['provider_id'], (string) $meta['barangay_code']) !== null) {
            return;
        }

        $station = LogisticsHub::query()
            ->where('logistics_provider_id', $meta['provider_id'])
            ->where('hub_type', LogisticsHub::STATION)
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->firstOrFail();

        $hubs->saveCoverage((int) $meta['provider_id'], [
            'name' => $meta['barangay_name'] ?: $meta['municipality'],
            'station_hub_id' => $station->id,
            'city_municipality_code' => $meta['city_municipality_code'],
            'barangay_code' => $meta['barangay_code'],
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function applyShipping(SellerShippingProviderService $shipping, array $meta): void
    {
        $seller = Seller::query()->findOrFail($meta['seller_id']);
        $provider = LogisticsProvider::query()->findOrFail($meta['provider_id']);

        try {
            $shipping->enable($seller, $provider);
        } catch (Throwable $e) {
            $this->command?->error("Could not enable shipping seller #{$seller->id} → provider #{$provider->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function applyRiderArea(array $meta): void
    {
        $courier = Courier::query()->findOrFail($meta['courier_id']);
        $stationId = (int) $meta['station_hub_id'];
        $preferred = array_values(array_filter(array_map('strval', $meta['preferred_barangay_codes'] ?? [])));

        $query = Area::query()
            ->where('logistics_provider_id', $meta['provider_id'])
            ->where('station_hub_id', $stationId)
            ->operationalCoverage();

        $area = null;
        if ($preferred !== []) {
            // Prefer seller/buyer barangays (e.g. Bayog) over arbitrary coverage.
            $area = (clone $query)->whereIn('barangay_code', $preferred)->orderBy('id')->first();
        }
        $area ??= $query->orderBy('id')->first();

        if ($area === null) {
            throw new \RuntimeException("No validated coverage under station #{$stationId} to assign rider courier #{$courier->id}.");
        }

        $courier->forceFill(['area_id' => $area->id])->save();
    }

    /** @param  array<string, mixed>  $meta */
    private function applyPartnership(ProviderHandoffSchemaService $partnerships, array $meta): void
    {
        $origin = LogisticsProvider::query()->findOrFail($meta['origin_provider_id']);
        $destination = LogisticsProvider::query()->findOrFail($meta['destination_provider_id']);
        $admin = Admin::query()->findOrFail($meta['admin_id']);

        $link = LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', $destination->id)
            ->whereIn('status', [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE])
            ->first();

        if ($link === null) {
            $link = $partnerships->requestLink($origin, $destination);
        }

        if ($link->isActive()) {
            return;
        }

        if ($link->destination_consented_at === null) {
            $link = $partnerships->accept($link, $destination);
        }

        if ($link->approved_at === null) {
            $link = $partnerships->approve($link, $admin);
        }
    }

    /** @return Collection<int, Buyer> */
    private function eligibleBuyers(): Collection
    {
        return Buyer::query()
            ->where('status', 'approved')
            ->whereNotNull('province_code')
            ->whereNotNull('municipality_code')
            ->whereNotNull('barangay_code')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Seller> */
    private function eligibleSellers(): Collection
    {
        return Seller::query()
            ->where('status', 'approved')
            ->whereNull('archived_at')
            ->whereNotNull('province_code')
            ->whereNotNull('municipality_code')
            ->whereNotNull('barangay_code')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, LogisticsProvider> */
    private function eligibleProviders(): Collection
    {
        return LogisticsProvider::query()
            ->where('status', 'approved')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Rider> */
    private function eligibleRiders(): Collection
    {
        return Rider::query()
            ->with('courier')
            ->eligibleForAssignment()
            ->orderBy('id')
            ->get();
    }

    private function partySummary(Buyer|Seller $party): array
    {
        return [
            'id' => $party->id,
            'email' => $party->email,
            'name' => $party instanceof Seller ? $party->business_name : trim($party->first_name.' '.$party->last_name),
            'province_code' => $party->province_code,
            'province_name' => $party->province_name,
            'municipality_code' => $party->municipality_code,
            'municipality_name' => $party->municipality_name,
            'barangay_code' => $party->barangay_code,
            'barangay_name' => $party->barangay_name,
        ];
    }

    /** @param  array<string, mixed>  $meta */
    private function queue(string $type, string $detail, array $meta = []): void
    {
        $this->actions[] = ['type' => $type, 'detail' => $detail, 'meta' => $meta];
    }

    private function shouldApply(): bool
    {
        return filter_var(getenv('LOGISTICS_FLOW_SEED_APPLY') ?: '0', FILTER_VALIDATE_BOOLEAN);
    }

    /** @param  array<string, mixed>  $plan */
    private function printReport(array $plan): void
    {
        $c = $plan['counts'];
        $this->command?->info('=== LogisticsFlowTestSeeder report ===');
        $this->command?->line("Eligible buyers={$c['buyers']} sellers={$c['sellers']} providers={$c['providers']} (verified={$c['verified_providers']}) riders={$c['riders']}");

        foreach ($plan['participants']['providers'] as $p) {
            $this->command?->line(sprintf(
                '  Provider #%d %s province=%s verified=%s',
                $p['id'],
                $p['business_name'],
                $p['province_name'] ?: 'none',
                $p['province_verified'] ? 'yes' : 'no',
            ));
        }
        foreach ($plan['participants']['sellers'] as $s) {
            $this->command?->line("  Seller #{$s['id']} {$s['name']} @ {$s['province_name']} / {$s['municipality_name']} / {$s['barangay_name']}");
        }
        foreach ($plan['participants']['buyers'] as $b) {
            $this->command?->line("  Buyer #{$b['id']} {$b['name']} @ {$b['province_name']} / {$b['municipality_name']} / {$b['barangay_name']}");
        }
        foreach ($plan['participants']['riders'] as $r) {
            $this->command?->line("  Rider #{$r['id']} provider=#{$r['provider_id']} station=#{$r['station_hub_id']} courier=#{$r['courier_id']} area=#{$r['area_id']}");
        }

        $this->command?->info('Scenarios:');
        foreach ($plan['scenarios'] as $name => $info) {
            $ok = ($info['possible_after_seed'] ?? false) ? 'YES' : 'NO';
            $extra = $info['reason'] ?? $info['note'] ?? '';
            $this->command?->line("  [{$ok}] {$name}".($extra !== '' ? " — {$extra}" : ''));
        }

        if ($plan['blockers'] !== []) {
            $this->command?->warn('Blockers / gaps:');
            foreach ($plan['blockers'] as $blocker) {
                $this->command?->line('  - '.$blocker);
            }
        }

        if ($plan['notes'] !== []) {
            $this->command?->info('Notes:');
            foreach ($plan['notes'] as $note) {
                $this->command?->line('  · '.$note);
            }
        }

        $this->command?->info('Planned writes ('.count($plan['actions']).'):');
        if ($plan['actions'] === []) {
            $this->command?->line('  (none — configuration already sufficient or blocked)');
        }
        foreach ($plan['actions'] as $i => $action) {
            $this->command?->line('  '.($i + 1).". [{$action['type']}] {$action['detail']}");
        }
    }
}
