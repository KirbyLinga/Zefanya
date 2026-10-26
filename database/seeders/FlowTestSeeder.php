<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Services\CourierAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Makes the pickup/delivery network ready for the REAL dev accounts so one
 * parcel can travel the whole pipeline:
 *
 *   buyer order -> seller ready -> logistics pickup dispatch -> rider collect
 *   -> logistics receive/sort -> rider deliver -> buyer confirm.
 *
 * It never creates users. Everything is looked up by email, so it either
 * improves the accounts that already exist or warns and skips.
 *
 * Idempotent: every write is an "ensure" (no duplicate rows on a second run).
 * Dev-only: refuses to run in production.
 *
 *   php artisan db:seed --class=FlowTestSeeder
 */
class FlowTestSeeder extends Seeder
{
    public const BUYER_EMAIL = 'kirby.linga08@gmail.com';

    public const SELLER_EMAIL = 'kirby4666@gmail.com';

    public const PROVIDER_EMAIL = 'penguinskipper9@gmail.com';

    public const RIDER_EMAIL = 'wofay32241@ensproxy.com';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('FlowTestSeeder refuses to run in production.');

            return;
        }

        $buyer = Buyer::query()->where('email', self::BUYER_EMAIL)->first();
        $seller = Seller::query()->where('email', self::SELLER_EMAIL)->first();
        $provider = LogisticsProvider::query()->where('email', self::PROVIDER_EMAIL)->first();
        $rider = Rider::query()->where('email', self::RIDER_EMAIL)->first();

        $missing = array_keys(array_filter([
            'buyer '.self::BUYER_EMAIL => $buyer === null,
            'seller '.self::SELLER_EMAIL => $seller === null,
            'logistics '.self::PROVIDER_EMAIL => $provider === null,
            'rider '.self::RIDER_EMAIL => $rider === null,
        ]));

        if ($missing !== []) {
            $this->command?->error('No existing user for: '.implode(', ', $missing).'. Nothing was changed — register them first, this seeder never creates accounts.');

            return;
        }

        /** @var array{created: list<string>, updated: list<string>, warnings: list<string>} $network */
        $network = [];
        /** @var array{created: list<string>, updated: list<string>, warnings: list<string>} $catalog */
        $catalog = [];

        $networkSeeder = new FlowNetworkSeeder;
        $catalogSeeder = new FlowCatalogSeeder;

        DB::transaction(function () use (
            $buyer, $seller, $provider, $rider, $networkSeeder, $catalogSeeder, &$network, &$catalog
        ): void {
            $catalog = $catalogSeeder->seed($buyer, $seller);
            $network = $networkSeeder->seed($buyer, $seller, $provider, $rider);
        });

        $this->printSummary($buyer, $seller, $provider, $rider, $network, $catalog);
        $this->printEligibility($seller, $provider);
        $this->printChecklist();
    }

    /**
     * @param  array{created: list<string>, updated: list<string>, warnings: list<string>}  $network
     * @param  array{created: list<string>, updated: list<string>, warnings: list<string>}  $catalog
     */
    private function printSummary(Buyer $buyer, Seller $seller, LogisticsProvider $provider, Rider $rider, array $network, array $catalog): void
    {
        $this->command?->info('Using these existing accounts (no user was created):');

        $this->command?->table(
            ['Role', 'Id', 'Name', 'Email', 'Status'],
            [
                ['Buyer', $buyer->id, trim($buyer->first_name.' '.$buyer->last_name), $buyer->email, (string) $buyer->status],
                ['Seller', $seller->id, $seller->business_name, $seller->email, (string) $seller->status],
                ['Logistics', $provider->id, $provider->business_name, $provider->email, $provider->status->value],
                ['Rider', $rider->id, $rider->fullName(), $rider->email, (string) $rider->status],
            ],
        );

        foreach ([
            'created' => array_merge($catalog['created'], $network['created']),
            'updated' => array_merge($catalog['updated'], $network['updated']),
        ] as $label => $lines) {
            if ($lines === []) {
                continue;
            }

            $this->command?->line(strtoupper($label).':');

            foreach (array_values(array_unique($lines)) as $line) {
                $this->command?->line('  - '.$line);
            }
        }

        foreach (array_values(array_unique(array_merge($catalog['warnings'], $network['warnings']))) as $warning) {
            $this->command?->warn('  ! '.$warning);
        }
    }

    /**
     * Read-only dry run of the same predicates CourierAssignmentService uses
     * in candidateFor(), evaluated for the seller's pickup barangay.
     */
    private function printEligibility(Seller $seller, LogisticsProvider $provider): void
    {
        $service = new CourierAssignmentService;
        $barangay = $seller->barangay_code;
        $weight = (int) $seller->products()->where('status', 'active')->max('weight_grams');

        $couriers = Courier::query()
            ->where('logistics_provider_id', $provider->id)
            ->withWorkload()
            ->with('rider.station')
            ->orderBy('id')
            ->get();

        $rows = [];
        $eligible = 0;

        foreach ($couriers as $courier) {
            $reasons = [];

            if (! $courier->isEligibleForAssignment()) {
                $reasons[] = 'rider not approved/verified/at an operational station';
            }
            if (! $courier->is_available) {
                $reasons[] = 'marked unavailable';
            }
            if ($courier->activeWorkload() >= Courier::ACTIVE_CAPACITY) {
                $reasons[] = 'workload at capacity';
            }
            if ($courier->max_weight_grams < $weight) {
                $reasons[] = 'weight limit '.$courier->max_weight_grams.'g < parcel '.$weight.'g';
            }
            if (! $service->coversBarangay($courier, $barangay)) {
                $reasons[] = 'station does not cover barangay '.$barangay;
            }

            if ($reasons === []) {
                $eligible++;

                $rows[] = [$courier->id, $courier->name, 'ELIGIBLE', '-'];
            } else {
                $rows[] = [$courier->id, $courier->name, 'blocked', implode('; ', $reasons)];
            }
        }

        $this->command?->info('Pickup eligibility for barangay '.$barangay.' (parcel ~'.$weight.'g):');
        $this->command?->table(['Courier', 'Rider', 'Result', 'Why'], $rows);

        $eligible > 0
            ? $this->command?->info($eligible.' rider(s) eligible for the pickup leg.')
            : $this->command?->warn('No eligible rider yet — fix the reasons above before running the flow.');
    }

    private function printChecklist(): void
    {
        $this->command?->info('Flow checklist (log in with the account shown):');

        $this->command?->table(
            ['#', 'Step', 'Account', 'Page'],
            [
                ['1', 'Buyer places the order', self::BUYER_EMAIL, '/buyer/cart -> /buyer/checkout'],
                ['2', 'Seller accepts + Mark ready', self::SELLER_EMAIL, '/seller/orders'],
                ['3', 'Logistics assigns pickup rider + confirms dispatch', self::PROVIDER_EMAIL, '/logistics/incoming-parcels'],
                ['4', 'Rider collects (F2 scan)', self::RIDER_EMAIL, '/rider/pickups (+ /rider/scan)'],
                ['5', 'Logistics receives + sorts + assigns rider', self::PROVIDER_EMAIL, '/logistics/incoming-parcels -> /logistics/parcel-sorting'],
                ['6', 'Rider delivers (proof + exact COD)', self::RIDER_EMAIL, '/rider/deliveries'],
                ['7', 'Buyer confirms received', self::BUYER_EMAIL, '/buyer/orders/{order}'],
            ],
        );
    }
}
