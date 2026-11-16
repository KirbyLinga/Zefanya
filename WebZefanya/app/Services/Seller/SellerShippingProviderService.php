<?php

namespace App\Services\Seller;

use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ProviderProvinceService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Seller shipping-provider opt-in: eligible Logistics companies for a seller's
 * registered province + pickup barangay, with enable/disable ownership checks.
 */
class SellerShippingProviderService
{
    public function __construct(
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly ProviderProvinceService $provinces = new ProviderProvinceService,
    ) {}

    public function canOfferShipping(Seller $seller): bool
    {
        return $this->enabledEligibleProviders($seller)->isNotEmpty();
    }

    /**
     * Providers the seller may enable: approved, verified province matching the
     * seller, live operational Province Hub, and operational origin coverage.
     *
     * @return Collection<int, LogisticsProvider>
     */
    public function eligibleProviders(Seller $seller): Collection
    {
        if (blank($seller->province_code) || blank($seller->barangay_code)) {
            return collect();
        }

        if (! $this->hubs->coverageSchemaAvailable()) {
            return collect();
        }

        return LogisticsProvider::query()
            ->where('status', 'approved')
            ->whereNotNull('province_verified_at')
            ->where('province_code', $seller->province_code)
            ->orderBy('business_name')
            ->get()
            ->filter(fn (LogisticsProvider $provider): bool => $this->isEligible($seller, $provider))
            ->values();
    }

    public function isEligible(Seller $seller, LogisticsProvider $provider): bool
    {
        if (! $provider->isApproved() || ! $provider->hasVerifiedProvince()) {
            return false;
        }

        if (blank($seller->province_code) || blank($seller->barangay_code)) {
            return false;
        }

        if ((string) $provider->province_code !== (string) $seller->province_code) {
            return false;
        }

        $hub = $this->provinces->activeProvinceHub($provider);
        if ($hub === null || ! $hub->isOperational() || ! $hub->isLiveProvinceHub()) {
            return false;
        }

        return $this->hubs->areaForBarangay((int) $provider->id, (string) $seller->barangay_code) !== null;
    }

    /**
     * @return Collection<int, LogisticsProvider>
     */
    public function enabledEligibleProviders(Seller $seller): Collection
    {
        $enabledIds = SellerShippingProvider::query()
            ->where('seller_id', $seller->id)
            ->where('is_enabled', true)
            ->pluck('logistics_provider_id');

        if ($enabledIds->isEmpty()) {
            return collect();
        }

        return $this->eligibleProviders($seller)
            ->filter(fn (LogisticsProvider $p): bool => $enabledIds->contains($p->id))
            ->values();
    }

    /**
     * Rows for the Seller UI: eligible providers plus any previously saved
     * links that became unavailable.
     *
     * @return list<array{
     *     provider: LogisticsProvider,
     *     eligible: bool,
     *     enabled: bool,
     *     unavailable_reason: ?string,
     *     hub: ?LogisticsHub,
     * }>
     */
    public function catalogForSeller(Seller $seller): array
    {
        $eligible = $this->eligibleProviders($seller)->keyBy('id');

        $saved = SellerShippingProvider::query()
            ->where('seller_id', $seller->id)
            ->with('logisticsProvider')
            ->get()
            ->keyBy('logistics_provider_id');

        $providerIds = $eligible->keys()
            ->merge($saved->keys())
            ->unique()
            ->values();

        $providers = LogisticsProvider::query()
            ->whereIn('id', $providerIds)
            ->orderBy('business_name')
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($providerIds as $providerId) {
            $provider = $providers->get($providerId);
            if ($provider === null) {
                continue;
            }

            $isEligible = $eligible->has($providerId);
            $pivot = $saved->get($providerId);
            $enabled = $pivot !== null && $pivot->is_enabled;

            $rows[] = [
                'provider' => $provider,
                'eligible' => $isEligible,
                'enabled' => $enabled,
                'unavailable_reason' => $isEligible ? null : $this->unavailableReason($seller, $provider),
                'hub' => $this->provinces->activeProvinceHub($provider),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            // Enabled first, then eligible, then name.
            return [$b['enabled'], $b['eligible'], $a['provider']->business_name]
                <=> [$a['enabled'], $a['eligible'], $b['provider']->business_name];
        });

        return $rows;
    }

    public function enable(Seller $seller, LogisticsProvider $provider): SellerShippingProvider
    {
        if (! $this->isEligible($seller, $provider)) {
            throw ValidationException::withMessages([
                'provider' => 'This logistics provider is not available for your registered pickup location.',
            ]);
        }

        $row = SellerShippingProvider::query()->firstOrNew([
            'seller_id' => $seller->id,
            'logistics_provider_id' => $provider->id,
        ]);
        $row->is_enabled = true;
        $row->save();

        return $row->fresh();
    }

    public function disable(Seller $seller, LogisticsProvider $provider): SellerShippingProvider
    {
        $row = SellerShippingProvider::query()
            ->where('seller_id', $seller->id)
            ->where('logistics_provider_id', $provider->id)
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'provider' => 'You have not enabled this logistics provider.',
            ]);
        }

        if ($row->is_enabled) {
            $enabledEligible = $this->enabledEligibleProviders($seller);
            $isLastEligible = $enabledEligible->count() === 1
                && (int) $enabledEligible->first()->id === (int) $provider->id;

            if ($isLastEligible) {
                throw ValidationException::withMessages([
                    'provider' => 'Keep at least one eligible shipping provider enabled to offer shipping.',
                ]);
            }
        }

        $row->is_enabled = false;
        $row->save();

        return $row->fresh();
    }

    private function unavailableReason(Seller $seller, LogisticsProvider $provider): string
    {
        if (! $provider->isApproved()) {
            return 'Provider is not approved.';
        }
        if (! $provider->hasVerifiedProvince()) {
            return 'Provider province is not verified.';
        }
        if ((string) $provider->province_code !== (string) $seller->province_code) {
            return 'Provider does not serve your registered province.';
        }

        $hub = $this->provinces->activeProvinceHub($provider);
        if ($hub === null || ! $hub->isLiveProvinceHub()) {
            return 'No live Province Hub is provisioned.';
        }
        if (! $hub->isOperational()) {
            return 'Province Hub is not operational.';
        }
        if ($this->hubs->areaForBarangay((int) $provider->id, (string) $seller->barangay_code) === null) {
            return 'No operational coverage for your pickup barangay.';
        }

        return 'Provider is currently unavailable.';
    }
}
