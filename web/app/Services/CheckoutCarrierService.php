<?php

namespace App\Services;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Buyer checkout carrier quotes for Phase 3.2.
 *
 * Same-province routes use Seller-enabled providers with operational destination
 * coverage. Cross-province routes require an approved directed provider link to
 * a destination partner that covers the buyer's barangay; fees come from an
 * explicit configured rule and are never read from the client.
 */
class CheckoutCarrierService
{
    public const SESSION_KEY = 'checkout_carrier_selection';

    public function __construct(
        private readonly SellerShippingProviderService $sellerShipping = new SellerShippingProviderService,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly ParcelHandoffService $handoffs = new ParcelHandoffService,
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
    public function optionsForCheckout(Buyer $buyer, Collection $sellerGroups): array
    {
        $rows = [];

        foreach ($sellerGroups as $group) {
            /** @var Seller $seller */
            $seller = $group['seller'];
            /** @var Collection<int, CartItem> $items */
            $items = $group['items'];
            $groupSubtotal = (int) $items->sum(fn (CartItem $item): int => $item->lineTotalCentavos());

            $rows[] = $this->optionsForSellerGroup($buyer, $seller, $groupSubtotal);
        }

        return $rows;
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
    public function optionsForSellerGroup(Buyer $buyer, Seller $seller, int $groupSubtotalMinor): array
    {
        $base = [
            'seller_id' => (int) $seller->id,
            'seller_name' => (string) $seller->business_name,
            'group_subtotal_minor' => $groupSubtotalMinor,
        ];

        if (blank($seller->province_code) || blank($seller->barangay_code)
            || blank($buyer->province_code) || blank($buyer->barangay_code)) {
            return $base + [
                'route' => 'incomplete',
                'available' => false,
                'message' => 'Complete both the seller pickup address and your delivery address (province and barangay) before choosing a carrier.',
                'carriers' => [],
            ];
        }

        if ((string) $seller->province_code !== (string) $buyer->province_code) {
            $fee = $this->crossProvinceFeeMinor($groupSubtotalMinor);
            $carriers = [];
            $hasApprovedPath = false;

            foreach ($this->sellerShipping->enabledEligibleProviders($seller) as $provider) {
                if ($provider->archived_at !== null) {
                    continue;
                }
                $links = $this->handoffs->serviceableLinks(
                    $provider,
                    (string) $buyer->barangay_code,
                    (string) $buyer->province_code,
                );

                if ($links->isEmpty()) {
                    continue;
                }

                $hasApprovedPath = true;

                if ($fee === null) {
                    continue;
                }

                $destination = $links->first()->destinationProvider;
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

            if ($fee === null) {
                return $base + [
                    'route' => 'cross_province',
                    'available' => false,
                    'message' => $hasApprovedPath
                        ? 'Cross-province shipping is not configured by the marketplace yet. Checkout for this route is temporarily unavailable.'
                        : 'Cross-province delivery is not available yet. Destination-provider links are required before a carrier can serve this route.',
                    'carriers' => [],
                ];
            }

            if ($carriers === []) {
                return $base + [
                    'route' => 'cross_province',
                    'available' => false,
                    'message' => 'Cross-province delivery is not available yet. Destination-provider links are required before a carrier can serve this route.',
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
        foreach ($this->sellerShipping->enabledEligibleProviders($seller) as $provider) {
            if ($provider->archived_at !== null) {
                continue;
            }
            if (! $this->hasDestinationCoverage($provider, (string) $buyer->barangay_code)) {
                continue;
            }

            $fee = $this->feeMinor($seller, $buyer, $groupSubtotalMinor);
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
                'message' => 'No shipping carrier serves this route for '.$seller->business_name.'. Ask the seller to enable a provider that covers your barangay, or change your delivery address.',
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
    public function validateSelection(Buyer $buyer, Collection $sellerGroups, array $submitted): array
    {
        $options = $this->optionsForCheckout($buyer, $sellerGroups);
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

    /** Requote from locked route rows inside the checkout transaction. */
    public function validateSelectionForOrder(Buyer $buyer, Collection $sellerGroups, array $submitted): array
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
            if (! $enabledForSeller || $provider === null || ! $provider->isApproved() || $provider->archived_at !== null
                || ! $provider->hasVerifiedProvince() || blank($seller->province_code)
                || blank($buyer->province_code) || (string) $provider->province_code !== (string) $seller->province_code
                || $origin === null || $provinceHub === null
                || ! $provinceHub->isLiveProvinceHub() || ! $provinceHub->isOperational()) {
                throw ValidationException::withMessages([
                    'carriers.'.$seller->id => 'The selected carrier no longer serves this route. Review carrier options and your delivery address.',
                ]);
            }

            $subtotal = (int) $group['items']->sum(fn (CartItem $item): int => $item->lineTotalCentavos());

            if ((string) $seller->province_code !== (string) $buyer->province_code) {
                // Cross-province: the Seller-enabled origin keeps commercial
                // ownership. The destination is resolved only from active,
                // approved links and must still cover the buyer's barangay.
                $links = $this->handoffs->serviceableLinks(
                    $provider,
                    (string) $buyer->barangay_code,
                    (string) $buyer->province_code,
                    lock: true,
                );
                $link = $links->first();
                $destinationProvider = $link !== null
                    ? LogisticsProvider::query()->whereKey($link->destination_provider_id)->lockForUpdate()->first()
                    : null;
                $destinationArea = $destinationProvider !== null
                    ? $this->hubs->areaForBarangay((int) $destinationProvider->id, (string) $buyer->barangay_code, lock: true)
                    : null;
                $fee = $this->crossProvinceFeeMinor($subtotal);

                if ($link === null || $destinationProvider === null || $destinationArea === null
                    || ! $destinationProvider->isApproved() || $destinationProvider->archived_at !== null
                    || ! $destinationProvider->hasVerifiedProvince()
                    || (string) $destinationProvider->province_code !== (string) $buyer->province_code) {
                    throw ValidationException::withMessages([
                        'carriers.'.$seller->id => 'The selected cross-province route is no longer serviceable. Review carrier options and your delivery address.',
                    ]);
                }

                if ($fee === null) {
                    throw ValidationException::withMessages([
                        'carriers.'.$seller->id => 'Cross-province shipping is not configured by the marketplace yet.',
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
            $fees[$seller->id] = $this->feeMinor($seller, $buyer, $subtotal);
        }

        return [
            'selections' => $selections,
            'fees' => $fees,
            'shipping_total_minor' => array_sum($fees),
            'destinations' => $destinations,
        ];
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
     * Explicit configured cross-province rule. Null means the marketplace has
     * not configured a rate, so the route must stay unavailable rather than
     * inventing a price.
     */
    private function crossProvinceFeeMinor(int $groupSubtotalMinor): ?int
    {
        $configured = config('buyer.cross_province.fee_minor')
            ?? config('buyer.cross_province_shipping_fee_minor')
            ?? config('buyer.cross_province_fee_minor');
        if ((! is_int($configured) && (! is_string($configured) || ! ctype_digit($configured)))
            || (int) $configured < 0 || filter_var($configured, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $threshold = config('buyer.cross_province.free_shipping_threshold_minor')
            ?? config('buyer.cross_province_shipping_free_threshold_minor')
            ?? config('buyer.cross_province_free_shipping_threshold_minor');
        if ((is_int($threshold) || (is_string($threshold) && ctype_digit($threshold)))
            && filter_var($threshold, FILTER_VALIDATE_INT) !== false && (int) $threshold >= 0
            && $groupSubtotalMinor >= (int) $threshold) {
            return 0;
        }

        return (int) $configured;
    }

    /**
     * Deterministic same-province fee. Never read from the request.
     */
    private function feeMinor(Seller $seller, Buyer $buyer, int $groupSubtotalMinor): int
    {
        $threshold = (int) config('buyer.announcements.free_shipping_threshold_minor', 150000);
        if ($groupSubtotalMinor >= $threshold) {
            return 0;
        }

        if (filled($seller->municipality_code)
            && filled($buyer->municipality_code)
            && (string) $seller->municipality_code === (string) $buyer->municipality_code) {
            return 4900;
        }

        return 6900;
    }
}
