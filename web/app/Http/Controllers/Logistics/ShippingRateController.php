<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsShippingRate;
use App\Services\Logistics\ShippingRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    private const AMOUNT = 'regex:/\A\d{1,6}(?:\.\d{1,2})?\z/';

    public function index(Request $request, ShippingRateService $rates): View
    {
        $own = LogisticsShippingRate::query()
            ->where('logistics_provider_id', $request->user('logistics')->getKey())
            ->get()->keyBy('zone');

        return view('Logistics.ShippingRates.index', [
            'zones' => collect(LogisticsShippingRate::ZONES)->map(fn (array $zone, string $key): array => $zone + [
                'own' => $own->get($key),
                'marketplace' => $rates->marketplaceRate($key),
            ]),
            'freeShippingMinor' => (int) config('buyer.announcements.free_shipping_threshold_minor', 150000),
        ]);
    }

    public function update(Request $request, ShippingRateService $rates): RedirectResponse
    {
        $rules = ['reason' => ['nullable', 'string', 'max:500']];
        $messages = [];
        foreach (LogisticsShippingRate::ZONES as $zone => $definition) {
            $rules["rates.{$zone}.first_kg"] = ['nullable', 'string', self::AMOUNT, 'not_regex:/\A0+(?:\.0+)?\z/', "required_with:rates.{$zone}.additional_kg"];
            $rules["rates.{$zone}.additional_kg"] = ['nullable', 'string', self::AMOUNT, 'not_regex:/\A0+(?:\.0+)?\z/', "required_with:rates.{$zone}.first_kg"];
            $label = $definition['label'];
            $messages += [
                "rates.{$zone}.first_kg.regex" => "{$label}: enter the first kilo as a peso amount, like 49 or 49.50.",
                "rates.{$zone}.first_kg.not_regex" => "{$label}: the first kilo must cost at least ₱0.01. Leave both boxes blank to use the marketplace rate.",
                "rates.{$zone}.first_kg.required_with" => "{$label}: enter the first kilo too, or leave both boxes blank.",
                "rates.{$zone}.additional_kg.not_regex" => "{$label}: each extra kilo must cost at least ₱0.01. Leave both boxes blank to use the marketplace rate.",
                "rates.{$zone}.additional_kg.regex" => "{$label}: enter each extra kilo as a peso amount, like 15 or 15.50.",
                "rates.{$zone}.additional_kg.required_with" => "{$label}: enter each extra kilo too (minimum ₱0.01), or leave both boxes blank.",
            ];
        }
        $data = $request->validate($rules, $messages);

        $input = [];
        foreach (array_keys(LogisticsShippingRate::ZONES) as $zone) {
            $input[$zone] = [
                'first_kg_minor' => $this->minor($data['rates'][$zone]['first_kg'] ?? null),
                'additional_kg_minor' => $this->minor($data['rates'][$zone]['additional_kg'] ?? null),
            ];
        }

        $changed = $rates->save($request->user('logistics'), $input, $data['reason'] ?? null);

        return redirect()->route('logistics.shipping-rates.index')->with(
            'success',
            $changed > 0 ? 'Shipping rates saved. Only checkouts started after this change use the new rates. Open checkouts and placed orders keep their rates.' : 'No rates were changed.',
        );
    }

    /** Pesos to centavos using integers, matching COD remittances. */
    private function minor(?string $pesos): ?int
    {
        if ($pesos === null) {
            return null;
        }

        [$whole, $centavos] = array_pad(explode('.', $pesos, 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad($centavos, 2, '0');
    }
}
