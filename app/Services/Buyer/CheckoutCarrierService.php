<?php

namespace App\Services\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsShippingRate;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\RoutePlannerService;
use App\Services\Logistics\ShippingRateService;
use App\Services\Seller\SellerShippingProviderService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Buyer checkout carrier quotes for Phase 3.2.
 *
 * Same-province routes use Seller-enabled providers with operational destination
 * coverage. Cross-province routes require an approved directed provider link to
 * a destination partner that covers the buyer's barangay; fees come from the
 * carrier's rate or the marketplace rule and are never read from the client.
 */
class CheckoutCarrierService
{
    public const SESSION_KEY = 'checkout_carrier_selection';

    public function __construct(
        private readonly SellerShippingProviderService $sellerShipping = new SellerShippingProviderService,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly ShippingRateService $rates = new ShippingRateService,
    ) {}

    /**
     * Build per-seller carrier options for the checkout page.
     *
     * @param  Collection<int, array{seller: Seller, items: Collection<int, CartItem>}>  $sellerGroups
     * @return list<array{
     *     seller_id: int,
     *     seller_name: string,
     *     route: 'same_province'|'cross_province'|'incomplete',
     *     available: bool,
     *     message: ?string,
     *     carriers: list<array{id: int, name: string, fee_minor: int, fee_formatted: string}>,
     *     group_subtotal_minor: int,
     * }>
     */
    public function optionsForCheckout(Buyer $buyer, Collection $sellerGroups, ?array $rateSnapshot = null): array
    {
        $rows = [];

        foreach ($sellerGroups as $group) {
            /** @var Seller $seller */
            $seller = $group['seller'];
            /** @var Collection<int, CartItem> $items */
            $items = $group['items'];
            $groupSubtotal = (int) $items->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
            $groupWeight = (int) $items->sum(fn (CartItem $item): int => $item->lineWeightGrams());

            $rows[] = $this->optionsForSellerGroup($buyer, $seller, $groupSubtotal, $groupWeight, $rateSnapshot);
        }

        return $rows;
    }

    /** Whole kilos a parcel is charged for: always rounded up, and at least one. */
    public static function chargeableKg(int $weightGrams): int
    {
        return max(1, (int) ceil($weightGrams / 1000));
    }

    /**
     * Identifies every fee the buyer was shown. The checkout page sends it back,
     * so a changed route or cart stops the order instead of charging differently.
     *
     * @param  list<array<string, mixed>>  $options
     */
    public function quoteFingerprint(array $options): string
    {
        $lines = [];

        foreach ($options as $row) {
            foreach ($row['carriers'] as $carrier) {
                $lines[] = $row['seller_id'].':'.$carrier['id'].':'.$carrier['fee_minor'];
            }
        }

        sort($lines);

        return hash('sha256', implode('|', $lines));
    }

    /**
     * @return array{
     *     seller_id: int,
     *     seller_name: string,
     *     route: 'same_province'|'cross_province'|'incomplete',
     *     available: bool,
     *     message: ?string,
     *     carriers: list<array{id: int, name: string, fee_minor: int, fee_formatted: string}>,
     *     group_subtotal_minor: int,
     * }
     */
    public function optionsForSellerGroup(Buyer $buyer, Seller $seller, int $groupSubtotalMinor, int $groupWeightGrams = 0, ?array $rateSnapshot = null): array
    {
        $base = [
            'seller_id' => (int) $seller->id,
            'seller_name' => (string) $seller->business_name,
            'group_subtotal_minor' => $groupSubtotalMinor,
            'chargeable_kg' => self::chargeableKg($groupWeightGrams),
        ];

        if (blank($buyer->province_code) || blank($buyer->barangay_code)) {
            return $base + [
                'route' => 'incomplete',
                'available' => false,
                'reason_code' => 'buyer_address_incomplete',
                'message' => 'Your delivery address is missing a province or barangay. Complete your saved address before choosing shipping.',
                'carriers' => [],
            ];
        }

        if (blank($seller->province_code) || blank($seller->barangay_code)) {
            return $base + [
                'route' => 'incomplete',
                'available' => false,
                'reason_code' => 'seller_address_incomplete',
                'message' => 'This shop has not completed its pickup address. Contact the seller to finish shipping setup; changing your address will not fix this.',
                'carriers' => [],
            ];
        }

        $enabledProviders = $this->sellerShipping->enabledEligibleProviders($seller)
            ->reject(fn (LogisticsProvider $provider): bool => $provider->archived_at !== null);
        if ($enabledProviders->isEmpty()) {
            return $base + [
                'route' => (string) $seller->province_code === (string) $buyer->province_code ? 'same_province' : 'cross_province',
                'available' => false,
                'reason_code' => 'seller_shipping_settings',
                'message' => 'No shipping carrier serves this route because this shop has no available pickup provider enabled. Contact the seller to enable shipping for its pickup address.',
                'carriers' => [],
            ];
        }

        if ((string) $seller->province_code !== (string) $buyer->province_code) {
            $carriers = [];
            $hasApprovedPath = false;

            foreach ($enabledProviders as $provider) {
                if ($provider->archived_at !== null || ($rateSnapshot !== null && ! array_key_exists($provider->id, $rateSnapshot))) {
                    continue;
                }

                // Phase 3: Check comprehensive route feasibility for cross-province
                $feasibilityReason = $this->routeFeasibilityReason($seller, $buyer, $provider);
                if ($feasibilityReason !== null) {
                    continue;  // Route not feasible, skip this provider
                }

                $route = $this->crossProvinceRoute($seller, $buyer, $provider);

                if ($route === null) {
                    continue;
                }

                $hasApprovedPath = true;

                $fee = $this->crossProvinceFeeMinor($provider, $groupSubtotalMinor, $groupWeightGrams, $rateSnapshot);
                if ($fee === null) {
                    continue;
                }

                $destination = $route['provider'];
                $carriers[] = [
                    'id' => (int) $provider->id,
                    'name' => (string) $provider->business_name,
                    'fee_minor' => $fee,
                    'fee_formatted' => $this->formatMinor($fee),
                    'cross_province' => true,
                    'destination_id' => (int) $destination?->id,
                    'destination_provider_id' => (int) $destination?->id,
                    'destination_name' => (string) ($destination?->business_name ?? 'Destination partner'),
                    'route_label' => (string) $provider->business_name.' → '.($destination?->business_name ?? 'destination partner'),
                ];
            }

            if ($carriers === []) {
                return $base + [
                    'route' => 'cross_province',
                    'available' => false,
                    'reason_code' => $hasApprovedPath ? 'shipping_rates_unavailable' : 'route_unavailable',
                    'message' => $hasApprovedPath
                        ? 'Shipping rates for this delivery route are not available yet. Please try again later or choose another delivery address.'
                        : 'Delivery from this shop to your address is not available yet. The shop has enabled shipping, but no connected route covers this address. Choose another saved delivery address or contact the seller.',
                    'carriers' => [],
                ];
            }

            return $base + [
                'route' => 'cross_province',
                'available' => true,
                'message' => null,
                'carriers' => $carriers,
            ];
        }

        $carriers = [];
        $destinationCovered = false;
        $ratesAvailable = false;
        foreach ($enabledProviders as $provider) {
            if ($rateSnapshot !== null && ! array_key_exists($provider->id, $rateSnapshot)) {
                continue;
            }
            $ratesAvailable = true;
            if (! $this->hasDestinationCoverage($provider, (string) $buyer->barangay_code)) {
                continue;
            }

            $destinationCovered = true;

            // Phase 3: Check comprehensive route feasibility
            $feasibilityReason = $this->routeFeasibilityReason($seller, $buyer, $provider);
            if ($feasibilityReason !== null) {
                continue;  // Route not feasible, skip this provider
            }

            $fee = $this->feeMinor($provider, $seller, $buyer, $groupSubtotalMinor, $groupWeightGrams, $rateSnapshot);
            $carriers[] = [
                'id' => (int) $provider->id,
                'name' => (string) $provider->business_name,
                'fee_minor' => $fee,
                'fee_formatted' => $this->formatMinor($fee),
            ];
        }

        if ($carriers === []) {
            return $base + [
                'route' => 'same_province',
                'available' => false,
                'reason_code' => ! $ratesAvailable ? 'shipping_rates_unavailable' : ($destinationCovered ? 'route_unavailable' : 'destination_coverage'),
                'message' => ! $ratesAvailable
                    ? 'Shipping rates for this delivery route are not available yet. Refresh checkout to review shipping options.'
                    : ($destinationCovered
                        ? 'No shipping carrier serves this route because a connected transport route is not available yet. The shop has enabled shipping; choose another saved address or contact the seller.'
                        : 'No shipping carrier serves this route because the enabled providers for this shop do not cover your delivery barangay. Choose another saved delivery address or contact the seller about coverage.'),
                'carriers' => [],
            ];
        }

        return $base + [
            'route' => 'same_province',
            'available' => true,
            'message' => null,
            'carriers' => $carriers,
        ];
    }

    /**
     * Validate buyer-submitted carrier ids. Never trusts submitted fees.
     *
     * @param  Collection<int, array{seller: Seller, items: Collection<int, CartItem>}>  $sellerGroups
     * @param  array<int|string, mixed>  $submitted  seller_id => logistics_provider_id
     * @return array{
     *     selections: array<int, int>,
     *     fees: array<int, int>,
     *     shipping_total_minor: int,
     *     options: list<array<string, mixed>>,
     * }
     *
     * @throws ValidationException
     */
    public function validateSelection(Buyer $buyer, Collection $sellerGroups, array $submitted, ?array $rateSnapshot = null): array
    {
        $options = $this->optionsForCheckout($buyer, $sellerGroups, $rateSnapshot);
        $selections = [];
        $fees = [];
        $errors = [];

        foreach ($options as $row) {
            $sellerId = (int) $row['seller_id'];

            if (! $row['available']) {
                $errors["carriers.{$sellerId}"] = $row['message'] ?? 'No carrier is available for this shop.';

                continue;
            }

            // Only a scalar carrier id is valid. Casting an array (for example
            // carriers[seller][id]=...) would silently become 1 and could match
            // a real provider, so malformed payloads fail closed.
            $raw = $submitted[$sellerId] ?? null;
            $chosen = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
            $match = collect($row['carriers'])->firstWhere('id', $chosen);

            if ($match === null) {
                $errors["carriers.{$sellerId}"] = 'Select a valid shipping carrier for '.$row['seller_name'].'.';

                continue;
            }

            $selections[$sellerId] = (int) $match['id'];
            $fees[$sellerId] = (int) $match['fee_minor'];
        }

        // Reject forged fee fields if present.
        if ($submitted !== [] && array_key_exists('shipping_fees', $submitted)) {
            $errors['shipping_fees'] = 'Shipping fees are calculated by the server and cannot be submitted.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'selections' => $selections,
            'fees' => $fees,
            'shipping_total_minor' => array_sum($fees),
            'options' => $options,
        ];
    }

    /** Revalidate locked routes using the rate snapshot captured when checkout started. */
    public function validateSelectionForOrder(Buyer $buyer, Collection $sellerGroups, array $submitted, ?array $rateSnapshot = null): array
    {
        $providerIds = array_values(array_unique(array_map('intval', $submitted)));
        sort($providerIds);
        $providers = LogisticsProvider::query()->whereIn('id', $providerIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $enabled = SellerShippingProvider::query()
            ->whereIn('seller_id', $sellerGroups->pluck('seller.id'))
            ->whereIn('logistics_provider_id', $providerIds)
            ->orderBy('id')->lockForUpdate()->get();
        $fees = [];
        $selections = [];
        $destinations = [];
        foreach ($sellerGroups as $group) {
            $seller = $group['seller'];
            $providerId = (int) ($submitted[$seller->id] ?? 0);
            $provider = $providers->get($providerId);
            $enabledForSeller = $enabled->contains(fn ($row): bool => (int) $row->seller_id === (int) $seller->id
                && (int) $row->logistics_provider_id === $providerId && $row->is_enabled);
            $origin = $this->hubs->areaForBarangay($providerId, $seller->barangay_code, lock: true);
            $provinceHub = LogisticsHub::query()
                ->where('logistics_provider_id', $providerId)
                ->where('hub_type', LogisticsHub::PROVINCE)
                ->whereNull('archived_at')->lockForUpdate()->first();
            if (($rateSnapshot !== null && ! array_key_exists($providerId, $rateSnapshot)) || ! $enabledForSeller || $provider === null || ! $provider->isApproved() || $provider->archived_at !== null
                || ! $provider->hasVerifiedProvince() || blank($seller->province_code)
                || blank($buyer->province_code) || (string) $provider->province_code !== (string) $seller->province_code
                || $origin === null || $provinceHub === null
                || ! $provinceHub->isLiveProvinceHub() || ! $provinceHub->isOperational()) {
                throw ValidationException::withMessages([
                    'carriers.'.$seller->id => 'The selected carrier no longer serves this route. Review carrier options and your delivery address.',
                ]);
            }

            $subtotal = (int) $group['items']->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
            $weight = (int) $group['items']->sum(fn (CartItem $item): int => $item->lineWeightGrams());

            if ((string) $seller->province_code !== (string) $buyer->province_code) {
                // Cross-province: the Seller-enabled origin keeps commercial
                // ownership. The destination comes from a connected route, whose
                // every provider change has an active approved link, and must
                // still cover the buyer's barangay.
                $route = $this->crossProvinceRoute($seller, $buyer, $provider);
                $destinationProvider = $route !== null
                    ? LogisticsProvider::query()->whereKey($route['provider']->id)->lockForUpdate()->first()
                    : null;
                $destinationArea = $destinationProvider !== null
                    ? $this->hubs->areaForBarangay((int) $destinationProvider->id, (string) $buyer->barangay_code, lock: true)
                    : null;
                $fee = $this->crossProvinceFeeMinor($provider, $subtotal, $weight, $rateSnapshot);

                if ($route === null || $destinationProvider === null || $destinationArea === null
                    || ! $destinationProvider->isApproved() || $destinationProvider->archived_at !== null
                    || ! $destinationProvider->hasVerifiedProvince()
                    || (string) $destinationProvider->province_code !== (string) $buyer->province_code) {
                    throw ValidationException::withMessages([
                        'carriers.'.$seller->id => 'The selected cross-province route is no longer serviceable. Review carrier options and your delivery address.',
                    ]);
                }

                if ($fee === null) {
                    throw ValidationException::withMessages([
                        'carriers.'.$seller->id => 'Shipping rates for this delivery route are not available yet.',
                    ]);
                }

                $destinations[$seller->id] = (int) $destinationProvider->id;
                $selections[$seller->id] = $providerId;
                $fees[$seller->id] = $fee;

                continue;
            }

            $destination = $this->hubs->areaForBarangay($providerId, $buyer->barangay_code, lock: true);
            if ($destination === null) {
                throw ValidationException::withMessages([
                    'carriers.'.$seller->id => 'The selected carrier no longer serves this route. Review carrier options and your delivery address.',
                ]);
            }

            $selections[$seller->id] = $providerId;
            $fees[$seller->id] = $this->feeMinor($provider, $seller, $buyer, $subtotal, $weight, $rateSnapshot);
        }

        return [
            'selections' => $selections,
            'fees' => $fees,
            'shipping_total_minor' => array_sum($fees),
            'destinations' => $destinations,
        ];
    }

    /**
     * Fees for the initial checkout total: the buyer's chosen carrier per
     * seller when it is offered, otherwise the first offered carrier. Display
     * only — the order re-validates the real selection (moved from
     * CheckoutController::defaultShippingFees).
     *
     * @param  list<array<string, mixed>>  $carrierOptions
     * @param  array<int|string, mixed>  $chosen  seller id => provider id
     * @return array<int, int>
     */
    public function defaultFees(array $carrierOptions, array $chosen = []): array
    {
        $fees = [];

        foreach ($carrierOptions as $row) {
            if (! ($row['available'] ?? false)) {
                continue;
            }

            $sellerId = (int) $row['seller_id'];
            $raw = $chosen[$sellerId] ?? $chosen[(string) $sellerId] ?? null;
            $choice = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
            $match = collect($row['carriers'])->firstWhere('id', $choice)
                ?? ($row['carriers'][0] ?? null);

            if ($match !== null) {
                $fees[$sellerId] = (int) $match['fee_minor'];
            }
        }

        return $fees;
    }

    public function allRoutesAvailable(array $options): bool
    {
        foreach ($options as $row) {
            if (! ($row['available'] ?? false)) {
                return false;
            }
        }

        return $options !== [];
    }

    public function formatMinor(int $minor): string
    {
        if ($minor === 0) {
            return 'Free';
        }

        return '₱'.number_format($minor / 100, 2);
    }

    private function hasDestinationCoverage(LogisticsProvider $provider, string $buyerBarangayCode): bool
    {
        return $this->hubs->areaForBarangay((int) $provider->id, $buyerBarangayCode) !== null;
    }

    /**
     * The origin carrier's cross-province rate, or the marketplace rule. Null
     * means neither is set, so the route must stay unavailable rather than
     * inventing a price.
     */
    private function crossProvinceFeeMinor(LogisticsProvider $provider, int $groupSubtotalMinor, int $groupWeightGrams, ?array $rateSnapshot = null): ?int
    {
        $rate = $this->quotedRate($provider, LogisticsShippingRate::CROSS_PROVINCE, $rateSnapshot);
        if ($rate === null) {
            return null;
        }

        return self::feeForRate($rate, $groupWeightGrams);
    }

    /**
     * Same-province fee from the carrier's rates. Deterministic, and never read
     * from the request.
     */
    private function feeMinor(LogisticsProvider $provider, Seller $seller, Buyer $buyer, int $groupSubtotalMinor, int $groupWeightGrams, ?array $rateSnapshot = null): int
    {
        $sameMunicipality = filled($seller->municipality_code)
            && filled($buyer->municipality_code)
            && (string) $seller->municipality_code === (string) $buyer->municipality_code;
        $rate = $this->quotedRate($provider, $sameMunicipality ? LogisticsShippingRate::SAME_MUNICIPALITY : LogisticsShippingRate::SAME_PROVINCE, $rateSnapshot);

        return self::feeForRate($rate, $groupWeightGrams);
    }

    /**
     * Base fee covers the first included_kg kilos (1 for rate snapshots taken
     * before the fixed fee), then each further whole kilo is added.
     * With the defaults: 1-2 kg = ₱50, 3 kg = ₱70, 4 kg = ₱90.
     *
     * @param  array{first_kg_minor: int, additional_kg_minor: int, included_kg?: int}  $rate
     */
    public static function feeForRate(array $rate, int $groupWeightGrams): int
    {
        $extraKg = max(0, self::chargeableKg($groupWeightGrams) - (int) ($rate['included_kg'] ?? 1));

        return (int) $rate['first_kg_minor'] + $extraKg * (int) $rate['additional_kg_minor'];
    }

    /** Capture rates while the caller creates the checkout token in the same transaction. */
    public function captureRates(Collection $sellerGroups): array
    {
        $providerIds = SellerShippingProvider::query()->whereIn('seller_id', $sellerGroups->pluck('seller.id'))
            ->where('is_enabled', true)->pluck('logistics_provider_id')->unique();
        $providers = LogisticsProvider::query()->whereIn('id', $providerIds)->orderBy('id')->lockForUpdate()->get();

        return $this->rates->snapshotForProviders($providers);
    }

    private function quotedRate(LogisticsProvider $provider, string $zone, ?array $snapshot): ?array
    {
        return $snapshot === null ? $this->rates->rateFor($provider, $zone) : ($snapshot[$provider->id][$zone] ?? null);
    }

    /**
     * Comprehensive route feasibility validation. Ensures a complete operational path
     * exists from seller origin to buyer destination.
     *
     * Returns null if route is feasible. Returns error message string if route
     * is impossible or incomplete.
     *
     * Phase 3 enhancement: Validates all prerequisites before checkout accepts the route.
     * Phase 4 enhancement: Validates complete multi-leg path through transport connections.
     */
    public function routeFeasibilityReason(Seller $seller, Buyer $buyer, LogisticsProvider $provider): ?string
    {
        // Prerequisite: seller and buyer have location data
        if (blank($seller->province_code) || blank($seller->barangay_code)
            || blank($buyer->province_code) || blank($buyer->barangay_code)) {
            return 'Complete both the seller pickup address and your delivery address (province and barangay) before validating shipping.';
        }

        // Prerequisite: provider is operational
        if (! $provider->isApproved() || $provider->archived_at !== null) {
            return 'The selected provider is not operational. Choose another carrier.';
        }

        // Prerequisite: provider has verified province
        if (! $provider->hasVerifiedProvince()) {
            return 'The selected provider has not verified their service province. Choose another carrier.';
        }

        // Validate origin (pickup) coverage
        $originArea = $this->hubs->areaForBarangay((int) $provider->id, $seller->barangay_code);
        if ($originArea === null) {
            return 'The selected provider does not serve the seller location ('.$seller->barangay_code.'). Choose another carrier or change seller address.';
        }

        // Validate origin Station Hub is operational
        $originStation = $originArea->station;
        if ($originStation === null || ! $originStation->isOperational()) {
            return 'The origin Station Hub for this provider is not operational. Choose another carrier.';
        }

        // Same-province route: destination must be in same province
        if ((string) $seller->province_code === (string) $buyer->province_code) {
            // Validate destination (delivery) coverage
            $destinationArea = $this->hubs->areaForBarangay((int) $provider->id, $buyer->barangay_code);
            if ($destinationArea === null) {
                return 'The selected provider does not serve the delivery location ('.$buyer->barangay_code.'). Choose another carrier or change delivery address.';
            }

            // Validate destination Station Hub is operational
            $destinationStation = $destinationArea->station;
            if ($destinationStation === null || ! $destinationStation->isOperational()) {
                return 'The destination Station Hub for this provider is not operational. Choose another carrier.';
            }

            // Phase 4: Check for complete connected path (same-provider multi-hub route)
            $reason = $this->validateConnectedPath($originStation, $destinationStation);
            if ($reason !== null) {
                return $reason;
            }

            // Same province, same provider: route is feasible
            return null;
        }

        // Cross-province route: every provider change on the path must be usable
        if ($this->crossProvinceRoute($seller, $buyer, $provider) === null) {
            return 'No connected delivery route exists from '.$provider->business_name.' to the delivery location. Cross-province shipping is unavailable for this route.';
        }

        return null;
    }

    /**
     * The final provider and legs for this seller and buyer through this origin
     * carrier, or null when no connected route exists. Checkout quotes and places
     * orders from this, so an unusable provider change never reaches an order.
     *
     * @return array{provider: LogisticsProvider, hub: LogisticsHub, legs: list<array<string, mixed>>}|null
     */
    private function crossProvinceRoute(Seller $seller, Buyer $buyer, LogisticsProvider $provider): ?array
    {
        $originStation = $this->hubs->areaForBarangay((int) $provider->id, $seller->barangay_code)?->station;

        return $originStation === null ? null : app(RoutePlannerService::class)->crossProvinceDestination(
            $provider,
            $originStation,
            (string) $buyer->barangay_code,
            (string) $buyer->province_code,
        );
    }

    /**
     * Phase 4: Validate that the same provider's hubs are connected. Different
     * hubs need a usable path; a deactivated connection does not count.
     *
     * @return string|null error message if path not found, null if feasible
     */
    private function validateConnectedPath(LogisticsHub $originHub, LogisticsHub $destinationHub): ?string
    {
        if ((int) $originHub->id === (int) $destinationHub->id
            || app(RoutePlannerService::class)->findPath($originHub, $destinationHub, $originHub->provider) !== []) {
            return null;
        }

        return 'No transport connection exists between origin and destination hubs. Route cannot be completed.';
    }
}
