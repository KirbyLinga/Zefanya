<?php

namespace Database\Seeders\Demo;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\TransferCourier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only summaries of what the demo seeders built: counts per area and the
 * account directory. Never writes to the database.
 */
class DemoReport
{
    /**
     * @return list<array{area: string, municipalities: int, barangays: int, stations: int, riders: int, couriers: int, buyers: int, sellers: int, products: int, coverage: float}>
     */
    public static function counts(): array
    {
        $rows = [];

        foreach (DemoNetwork::psgc() as $key => $psgc) {
            $provider = DemoNetwork::provider($key);
            $province = $psgc['province'][0];
            $barangays = array_sum(array_map(fn (array $m): int => count($m['barangays']), $psgc['municipalities']));
            $covered = $provider === null ? 0 : Area::query()->where('logistics_provider_id', $provider->id)
                ->where('is_active', true)->whereNotNull('barangay_code')
                ->whereHas('station', fn ($q) => $q->where('is_active', true)->where('approval_status', LogisticsHub::APPROVAL_APPROVED))
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('area_courier')
                    ->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')
                    ->join('riders', 'riders.id', '=', 'couriers.rider_id')
                    ->whereColumn('area_courier.area_id', 'areas.id')
                    ->where('riders.status', 'approved')->where('riders.is_online', true)
                    ->whereNotNull('riders.email_verified_at')
                    ->whereColumn('riders.station_hub_id', 'areas.station_hub_id'))
                ->count();

            $rows[] = [
                'area' => $psgc['province'][1].' ('.$province.')',
                'municipalities' => count($psgc['municipalities']),
                'barangays' => $barangays,
                'stations' => $provider === null ? 0 : LogisticsHub::query()->where('logistics_provider_id', $provider->id)->where('hub_type', LogisticsHub::STATION)->where('is_active', true)->count(),
                'riders' => $provider === null ? 0 : Rider::query()->where('logistics_provider_id', $provider->id)->count(),
                'couriers' => $provider === null ? 0 : TransferCourier::query()->where('logistics_provider_id', $provider->id)->count(),
                'buyers' => Buyer::query()->where('province_code', $province)->count(),
                'sellers' => Seller::query()->where('province_code', $province)->count(),
                'products' => Product::query()->whereIn('seller_id', Seller::query()->where('province_code', $province)->select('id'))->count(),
                'coverage' => $barangays === 0 ? 0.0 : round($covered / $barangays * 100, 2),
            ];
        }

        return $rows;
    }

    /** Markdown account directory: the shared password once, then every login. */
    public static function accountsMarkdown(): string
    {
        $lines = [
            '# Zefanya demo accounts',
            '',
            'Fictional accounts created by the demo seeders. Not real people or businesses.',
            '',
            '**Password for every account below: `'.DemoNetwork::PASSWORD.'`**',
            '',
            'Logins are the email addresses. New accounts use letters and digits only, so they are easy to type.',
            '',
            '## Logistics companies',
            '',
            '| Area | Company | Email |',
            '|---|---|---|',
        ];

        $existing = false;
        foreach (DemoNetwork::areaKeys() as $key) {
            $provider = DemoNetwork::provider($key);
            // A real company the demo is seeded into (Ninja Express in Laguna) is not a demo account: no login is listed.
            $isReal = $provider !== null && DemoNetwork::demoTag($provider, $key) !== '';
            $existing = $existing || $isReal;
            $lines[] = '| '.DemoNetwork::psgc()[$key]['province'][1].' | '.($provider?->business_name ?? '(not seeded)').($isReal ? ' (existing account)' : '').' | '.($isReal ? 'not a demo account' : ($provider?->email ?? '')).' |';
        }

        array_push($lines, '', '## Sellers', '', '| Email | Shop | Municipality | Barangay |', '|---|---|---|---|');

        foreach (Seller::query()->where('email', 'like', '%@'.DemoNetwork::DOMAIN)->orderBy('province_name')->orderBy('email')->get() as $seller) {
            $lines[] = '| '.$seller->email.' | '.$seller->business_name.' | '.$seller->municipality_name.' | '.$seller->barangay_name.' |';
        }

        $lines[] = '';
        $lines[] = '## Buyers';
        $lines[] = '';
        $lines[] = '| Email | Municipality | Barangay |';
        $lines[] = '|---|---|---|';

        foreach (Buyer::query()->where('email', 'like', '%@'.DemoNetwork::DOMAIN)->orderBy('province_name')->orderBy('email')->get() as $buyer) {
            $lines[] = '| '.$buyer->email.' | '.$buyer->municipality_name.' | '.$buyer->barangay_name.' |';
        }

        $lines[] = '';
        $lines[] = '## Riders';
        $lines[] = '';
        $lines[] = 'Rider emails follow `rider` + area code + municipality + number, e.g. `riderlagpila01@'.DemoNetwork::DOMAIN.'`.';
        $lines[] = 'Area codes: '.implode(', ', array_map(fn ($key, $code) => "{$code} = {$key}", array_keys(DemoNetwork::CODES), DemoNetwork::CODES)).'.';
        if ($existing) {
            $lines[] = 'Demo Riders of an existing company carry its tag after the area code, e.g. `riderlagp18calamba01@'.DemoNetwork::DOMAIN.'`, so they are easy to find and remove.';
        }
        $lines[] = 'Each Rider holds one to three barangays of one Station Hub. The full list is in `demo-accounts-riders.csv`.';
        $lines[] = '';
        $lines[] = '## Transport Couriers';
        $lines[] = '';
        $lines[] = 'Couriers are personnel records Logistics manages (Logistics > Couriers). They have no login.';
        $lines[] = '';
        $lines[] = '## Original scenario accounts (unchanged)';
        $lines[] = '';
        $lines[] = '| Role | Email |';
        $lines[] = '|---|---|';
        foreach (['buyer-pila', 'buyer-qc', 'buyer-davao', 'buyer-unsupported', 'seller-pila', 'seller-lumban', 'seller-bulacan', 'seller-batangas', 'logistics-laguna', 'logistics-bulacan', 'logistics-metro', 'logistics-batangas', 'logistics-davao', 'rider-pila', 'rider-lumban', 'rider-malolos', 'rider-qc', 'rider-batangas-city', 'rider-davao-city'] as $slug) {
            $lines[] = '| '.ucfirst(explode('-', $slug)[0]).' | '.DeliveryScenarioSeeder::email($slug).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /** CSV of every Rider: email, area, municipality, station, barangays. */
    public static function ridersCsv(): string
    {
        $out = ['email,area,municipality,station,barangays'];

        Rider::query()->where('email', 'like', 'rider%@'.DemoNetwork::DOMAIN)->with('station')->orderBy('email')->get()
            ->each(function (Rider $rider) use (&$out): void {
                $barangays = DB::table('area_courier')
                    ->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')
                    ->join('areas', 'areas.id', '=', 'area_courier.area_id')
                    ->where('couriers.rider_id', $rider->id)->orderBy('areas.barangay_code')->pluck('areas.barangay_name')->implode('; ');
                $out[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [
                    $rider->email, $rider->province_name, $rider->municipality_name, $rider->station?->name, $barangays,
                ]));
            });

        return implode("\n", $out)."\n";
    }
}
