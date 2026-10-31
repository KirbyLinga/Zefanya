<?php

namespace App\Services\Logistics;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsShippingRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Per-company shipping rates by zone and weight. A company with no rate for a
 * zone uses the marketplace rate from config/buyer.php.
 */
class ShippingRateService
{
    /**
     * Null means nobody has priced this zone, so the route must stay unavailable.
     *
     * @return array{first_kg_minor: int, additional_kg_minor: int}|null
     */
    public function rateFor(LogisticsProvider $provider, string $zone): ?array
    {
        $own = LogisticsShippingRate::query()
            ->where('logistics_provider_id', $provider->getKey())
            ->where('zone', $zone)
            ->whereNotNull('first_kg_minor')
            ->first();

        return $this->resolveRate($own, $zone);
    }

    /** Read the relevant providers' rate cards in one query for a checkout snapshot. */
    public function snapshotForProviders(Collection $providers): array
    {
        $cards = LogisticsShippingRate::query()->whereIn('logistics_provider_id', $providers->pluck('id'))
            ->whereNotNull('first_kg_minor')->get()->groupBy('logistics_provider_id');
        $snapshot = [];
        foreach ($providers as $provider) {
            foreach (array_keys(LogisticsShippingRate::ZONES) as $zone) {
                $own = $cards->get($provider->id)?->firstWhere('zone', $zone);
                $snapshot[$provider->id][$zone] = $this->resolveRate($own, $zone);
            }
        }

        return $snapshot;
    }

    private function resolveRate(?LogisticsShippingRate $own, string $zone): ?array
    {
        return $own !== null
            ? ['first_kg_minor' => $own->first_kg_minor, 'additional_kg_minor' => (int) $own->additional_kg_minor]
            : $this->marketplaceRate($zone);
    }

    /** @return array{first_kg_minor: int, additional_kg_minor: int}|null */
    public function marketplaceRate(string $zone): ?array
    {
        if ($zone === LogisticsShippingRate::CROSS_PROVINCE) {
            $configured = config('buyer.cross_province.fee_minor')
                ?? config('buyer.cross_province_shipping_fee_minor')
                ?? config('buyer.cross_province_fee_minor');
            if ((! is_int($configured) && (! is_string($configured) || ! ctype_digit($configured)))
                || (int) $configured < 0 || filter_var($configured, FILTER_VALIDATE_INT) === false) {
                return null;
            }

            return ['first_kg_minor' => (int) $configured, 'additional_kg_minor' => (int) config('buyer.cross_province.additional_kg_minor', 0)];
        }

        $rate = config('buyer.shipping_rates.'.$zone);

        return ['first_kg_minor' => (int) $rate['first_kg_minor'], 'additional_kg_minor' => (int) $rate['additional_kg_minor']];
    }

    /**
     * Save the whole rate card. Checkout captures its rate snapshot while holding the provider
     * row, so taking the same lock here makes snapshot creation atomic with rate edits.
     *
     * @param  array<string, array{first_kg_minor: ?int, additional_kg_minor: ?int}>  $rates
     * @return int how many zones changed
     */
    public function save(LogisticsProvider $provider, array $rates, ?string $reason = null): int
    {
        $rules = [];
        foreach (array_keys(LogisticsShippingRate::ZONES) as $zone) {
            $rules["rates.{$zone}.first_kg_minor"] = ['nullable', 'integer', 'min:1', 'max:99999999', "required_with:rates.{$zone}.additional_kg_minor"];
            $rules["rates.{$zone}.additional_kg_minor"] = ['nullable', 'integer', 'min:1', 'max:99999999', "required_with:rates.{$zone}.first_kg_minor"];
        }
        Validator::make(['rates' => $rates], $rules)->validate();

        return DB::transaction(function () use ($provider, $rates, $reason): int {
            $provider = LogisticsProvider::query()->whereKey($provider->getKey())->lockForUpdate()->firstOrFail();
            $changed = 0;

            foreach (LogisticsShippingRate::ZONES as $zone => $definition) {
                $first = $rates[$zone]['first_kg_minor'] ?? null;
                $additional = $first === null ? null : ($rates[$zone]['additional_kg_minor'] ?? 0);

                $row = LogisticsShippingRate::query()->firstOrNew(['logistics_provider_id' => $provider->getKey(), 'zone' => $zone]);
                $oldFirst = $row->first_kg_minor;
                $oldAdditional = $row->additional_kg_minor;
                if ($oldFirst === $first && $oldAdditional === $additional) {
                    continue;
                }

                $row->fill(['first_kg_minor' => $first, 'additional_kg_minor' => $additional])->save();
                OperationsAuditLog::record(
                    $provider, 'rate_changed', $row, $definition['label'],
                    ['first_kilo' => $this->auditAmount($oldFirst), 'each_extra_kilo' => $this->auditAmount($oldAdditional)],
                    ['first_kilo' => $this->auditAmount($first), 'each_extra_kilo' => $this->auditAmount($additional)],
                    $reason,
                );
                $changed++;
            }

            return $changed;
        }, 3);
    }

    private function auditAmount(?int $minor): string
    {
        return $minor === null ? 'Marketplace rate' : '₱'.number_format($minor / 100, 2);
    }
}
