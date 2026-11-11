<?php

namespace Database\Seeders;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\LogisticsShippingRate;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Services\Buyer\BuyerAddressBook;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Logistics\RoutePlannerService;
use App\Services\Logistics\TransferCourierService;
use App\Services\Seller\SellerShippingProviderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-contained demo network for the five delivery scenarios:
 *
 *   1. Local           Pila -> Pila            same Station Hub, no transfer
 *   2. Intra-province  Lumban -> Pila          manifest between own hubs
 *   3. Inter-province  Bulacan -> Quezon City  cross-provider handoff
 *   4. Inter-island    Batangas -> Davao       ROAD -> SEA (AIR standby) -> ROAD
 *   5. Unsupported     Pila -> Cebu City       checkout blocked; saved Pila address works
 *
 * Creates its own accounts (emails contain "zef-demo-"), so no real account,
 * hub, order or setting is touched. It never creates orders: every parcel is
 * placed and moved through the real screens. Provider links use the real
 * consent + Admin approval services, and transport connections keep their
 * model validation.
 *
 * Idempotent and dev-only. Delivery codes appear on the buyer's order page, so
 * no inbox is needed to finish a delivery. Other notices are still mailed; to
 * receive them, point the demo accounts at your own inbox (plus-addressing):
 *
 *   $env:DELIVERY_DEMO_MAILBOX='your.address@gmail.com'
 *   php artisan db:seed --class=DeliveryScenarioSeeder
 */
class DeliveryScenarioSeeder extends Seeder
{
    public const SLUG_PREFIX = 'zef-demo-';

    public const PASSWORD = 'Zefanya@Demo1';

    public const MAILBOX_ENV = 'DELIVERY_DEMO_MAILBOX';

    /**
     * Real PSGC codes (psgc.gitlab.io, checked 2026-11-02), so these addresses
     * match the barangay dropdowns. Province Hubs act as the configured gateways.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PROVIDERS = [
        'laguna' => [
            'name' => 'Demo Laguna Express',
            'region' => ['040000000', 'CALABARZON'],
            'province' => ['043400000', 'Laguna'],
            'hub' => ['Laguna Province Hub', '043426000', 'Santa Cruz', '043426001', 'Alipit'],
            'stations' => [
                'pila' => ['Pila Station Hub', '043422000', 'Pila', [['043422001', 'Aplaya'], ['043422003', 'Bukal']]],
                'lumban' => ['Lumban Station Hub', '043413000', 'Lumban', [['043413003', 'Balubad']]],
            ],
        ],
        'bulacan' => [
            'name' => 'Demo Bulacan Express',
            'region' => ['030000000', 'Central Luzon'],
            'province' => ['031400000', 'Bulacan'],
            'hub' => ['Bulacan Province Hub', '031410000', 'City of Malolos', '031410001', 'Anilao'],
            'stations' => [
                'malolos' => ['Malolos Station Hub', '031410000', 'City of Malolos', [['031410002', 'Atlag']]],
            ],
        ],
        'metro' => [
            'name' => 'Demo Metro Manila Express',
            'region' => ['130000000', 'NCR'],
            'province' => ['130000000', 'Metro Manila'],
            'hub' => ['Metro Manila Province Hub', '137404000', 'Quezon City', '137404002', 'Amihan'],
            'stations' => [
                'qc' => ['Quezon City Station Hub', '137404000', 'Quezon City', [['137404001', 'Alicia']]],
            ],
        ],
        'batangas' => [
            'name' => 'Demo Batangas Express',
            'region' => ['040000000', 'CALABARZON'],
            'province' => ['041000000', 'Batangas'],
            'hub' => ['Batangas Port Gateway', '041005000', 'Batangas City', '041005003', 'Balagtas'],
            'stations' => [
                'batangas-city' => ['Batangas City Station Hub', '041005000', 'Batangas City', [['041005001', 'Alangilan']]],
            ],
        ],
        'davao' => [
            'name' => 'Demo Davao Express',
            'region' => ['110000000', 'Davao Region'],
            'province' => ['112400000', 'Davao Del Sur'],
            'hub' => ['Davao Port Gateway', '112402000', 'City of Davao', '112402001', 'Acacia'],
            'stations' => [
                'davao-city' => ['Davao City Station Hub', '112402000', 'City of Davao', [['112402002', 'Agdao']]],
            ],
        ],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> slug => provider, station, name */
    public const SELLERS = [
        'seller-pila' => ['laguna', 'pila', 'Demo Pila Shop'],
        'seller-lumban' => ['laguna', 'lumban', 'Demo Lumban Shop'],
        'seller-bulacan' => ['bulacan', 'malolos', 'Demo Bulacan Shop'],
        'seller-batangas' => ['batangas', 'batangas-city', 'Demo Batangas Shop'],
    ];

    /** @var array<string, array{0: string, 1: string, 2: int}> slug => provider, station, barangay index */
    public const BUYERS = [
        'buyer-pila' => ['laguna', 'pila', 1],
        'buyer-qc' => ['metro', 'qc', 0],
        'buyer-davao' => ['davao', 'davao-city', 0],
    ];

    /** Directed partnerships, each with its reverse so failed deliveries can go home. */
    public const LINKS = [['bulacan', 'metro'], ['metro', 'bulacan'], ['batangas', 'davao'], ['davao', 'batangas']];

    /**
     * Each row is [origin provider, origin station|null, destination provider,
     * destination station|null, mode]; a null station means the Province Hub.
     * The AIR row is a standby: the planner takes the first connection (SEA)
     * and falls back to AIR only when SEA is deactivated.
     *
     * @var list<array{0: string, 1: ?string, 2: string, 3: ?string, 4: string}>
     */
    public const CONNECTIONS = [
        ['laguna', 'lumban', 'laguna', 'pila', TransportConnection::ROAD],
        ['bulacan', 'malolos', 'metro', 'qc', TransportConnection::ROAD],
        ['batangas', 'batangas-city', 'batangas', null, TransportConnection::ROAD],
        ['batangas', null, 'davao', null, TransportConnection::SEA],
        ['batangas', null, 'davao', null, TransportConnection::AIR],
        ['davao', null, 'davao', 'davao-city', TransportConnection::ROAD],
    ];

    /** No demo provider covers Cebu, which is what makes scenario 5 unsupported. */
    public const UNSUPPORTED_ADDRESS = [
        'address_mode' => 'manual',
        'province' => '072200000',
        'province_name' => 'Cebu',
        'municipality' => '072217000',
        'municipality_name' => 'City of Cebu',
        'barangay' => '072217003',
        'barangay_name' => 'Apas',
        'street' => '1 Demo Street',
        'postal_code' => '6000',
    ];

    /** @var array<string, array{0: int, 1: int}> zone => first kilo, each further kilo (centavos) */
    private const RATES = [
        LogisticsShippingRate::SAME_MUNICIPALITY => [4900, 1500],
        LogisticsShippingRate::SAME_PROVINCE => [6900, 2000],
        LogisticsShippingRate::CROSS_PROVINCE => [19900, 5000],
    ];

    /** @var array{created: list<string>, updated: list<string>} */
    private array $report = ['created' => [], 'updated' => []];

    /** @var array<string, LogisticsProvider> */
    private array $providers = [];

    /** @var array<string, LogisticsHub> */
    private array $provinceHubs = [];

    /** @var array<string, LogisticsHub> */
    private array $stations = [];

    public static function email(string $slug): string
    {
        $mailbox = getenv(self::MAILBOX_ENV);

        if (is_string($mailbox) && str_contains($mailbox, '@')) {
            [$name, $domain] = explode('@', $mailbox, 2);

            return $name.'+'.self::SLUG_PREFIX.$slug.'@'.$domain;
        }

        return self::SLUG_PREFIX.$slug.'@zefanya.test';
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DeliveryScenarioSeeder refuses to run in production.');

            return;
        }

        $migrator = app('migrator');
        $pending = array_diff(
            array_keys($migrator->getMigrationFiles([database_path('migrations')])),
            $migrator->getRepository()->getRan(),
        );

        if ($pending !== []) {
            $this->command?->error('Run "php artisan migrate" first; pending: '.implode(', ', $pending));

            return;
        }

        // The platform's own Admin approves partnerships; a demo Admin never does.
        $admin = Admin::query()->where('email', 'not like', '%'.self::SLUG_PREFIX.'%')->orderBy('id')->first();

        if ($admin === null) {
            $this->command?->error('An Admin must exist to approve provider partnerships. Run AdminSeeder first.');

            return;
        }

        DB::transaction(function () use ($admin): void {
            foreach (self::PROVIDERS as $key => $definition) {
                $this->network($key, $definition);
                $this->transferCourier($key, $definition['name']);
            }

            foreach (self::SELLERS as $slug => [$provider, $station, $name]) {
                $this->sellerWithProduct($slug, $provider, $station, $name);
            }

            foreach (self::BUYERS as $slug => [$provider, $station, $barangay]) {
                $this->buyer($slug, self::addressAt($provider, $station, $barangay));
            }

            $this->unsupportedBuyer();

            foreach (self::LINKS as [$origin, $destination]) {
                $this->link($this->providers[$origin], $this->providers[$destination], $admin);
            }

            foreach (self::CONNECTIONS as [$fromProvider, $fromStation, $toProvider, $toStation, $mode]) {
                $this->connection(
                    $fromStation === null ? $this->provinceHubs[$fromProvider] : $this->stations[$fromStation],
                    $toStation === null ? $this->provinceHubs[$toProvider] : $this->stations[$toStation],
                    $mode,
                );
            }
        });

        $this->printSummary();
        $this->printScenarioCheck();
    }

    /**
     * What checkout says for each scenario, using the same quote and planner
     * calls the buyer's order goes through.
     *
     * @return list<array{scenario: string, available: bool, expected: bool, route: string}>
     */
    public function scenarioCheck(): array
    {
        $unsupported = Buyer::query()->where('email', self::email('buyer-unsupported'))->firstOrFail();
        $addressBook = app(BuyerAddressBook::class);
        $saved = $unsupported->addresses()->where('is_default', false)->firstOrFail();

        $rows = [
            ['1 Local', $this->buyerAccount('buyer-pila'), 'seller-pila', true],
            ['2 Intra-province', $this->buyerAccount('buyer-pila'), 'seller-lumban', true],
            ['3 Inter-province', $this->buyerAccount('buyer-qc'), 'seller-bulacan', true],
            ['4 Inter-island', $this->buyerAccount('buyer-davao'), 'seller-batangas', true],
            ['5 Unsupported (Cebu default)', $unsupported, 'seller-pila', false],
            ['5 After switching to saved Pila', $addressBook->deliveringTo($unsupported, $saved), 'seller-pila', true],
        ];

        return array_map(function (array $row): array {
            [$scenario, $buyer, $sellerSlug, $expected] = $row;
            $seller = Seller::query()->where('email', self::email($sellerSlug))->firstOrFail();
            $quote = app(CheckoutCarrierService::class)->optionsForSellerGroup($buyer, $seller, 50000, 800);

            return [
                'scenario' => $scenario,
                'available' => $quote['available'],
                'expected' => $expected,
                'route' => $quote['available'] ? $this->describeRoute($seller, $buyer, (int) $quote['carriers'][0]['id']) : 'blocked: no valid delivery route',
            ];
        }, $rows);
    }

    private function describeRoute(Seller $seller, Buyer $buyer, int $providerId): string
    {
        $planner = app(RoutePlannerService::class);
        $provider = LogisticsProvider::query()->findOrFail($providerId);

        try {
            return $planner->describeRoute($planner->planRoute($seller, $provider, (string) $buyer->barangay_code, (string) $buyer->province_code));
        } catch (ValidationException $e) {
            return 'NOT ROUTABLE: '.$e->getMessage();
        }
    }

    /** @return array<string, string> */
    private static function addressAt(string $provider, string $station, int $barangay): array
    {
        $definition = self::PROVIDERS[$provider];
        [, $cityCode, $cityName, $barangays] = $definition['stations'][$station];

        return [
            'address_mode' => 'manual',
            'province_code' => $definition['province'][0],
            'province_name' => $definition['province'][1],
            'municipality_code' => $cityCode,
            'municipality_name' => $cityName,
            'barangay_code' => $barangays[$barangay][0],
            'barangay_name' => $barangays[$barangay][1],
            'street' => '1 Demo Street',
            'postal_code' => '4000',
        ];
    }

    /** @param  array<string, mixed>  $definition */
    private function network(string $key, array $definition): void
    {
        $provider = $this->account(LogisticsProvider::class, 'logistics-'.$key, [
            'business_name' => $definition['name'],
            'province_code' => $definition['province'][0],
            'province_name' => $definition['province'][1],
            'municipality_code' => $definition['hub'][1],
            'municipality_name' => $definition['hub'][2],
            'barangay_code' => $definition['hub'][3],
            'barangay_name' => $definition['hub'][4],
        ]);
        $this->providers[$key] = $provider;

        $location = [
            'logistics_provider_id' => $provider->id,
            'region_code' => $definition['region'][0],
            'region_name' => $definition['region'][1],
            'province_code' => $definition['province'][0],
            'province_name' => $definition['province'][1],
            'street' => '1 Demo Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ];

        $this->provinceHubs[$key] = LogisticsHub::query()->where('logistics_provider_id', $provider->id)->liveProvince()->first()
            ?? $this->created(LogisticsHub::query()->create($location + [
                'name' => $definition['hub'][0],
                'hub_type' => LogisticsHub::PROVINCE,
                'city_municipality_code' => $definition['hub'][1],
                'municipality' => $definition['hub'][2],
                'barangay_code' => $definition['hub'][3],
                'barangay_name' => $definition['hub'][4],
            ]), 'Province Hub '.$definition['hub'][0]);

        foreach ($definition['stations'] as $stationKey => [$name, $cityCode, $cityName, $barangays]) {
            $station = LogisticsHub::query()
                ->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::STATION)
                ->where('city_municipality_code', $cityCode)
                ->first()
                ?? $this->created(LogisticsHub::query()->create($location + [
                    'parent_hub_id' => $this->provinceHubs[$key]->id,
                    'name' => $name,
                    'hub_type' => LogisticsHub::STATION,
                    'city_municipality_code' => $cityCode,
                    'municipality' => $cityName,
                    'barangay_code' => $barangays[0][0],
                    'barangay_name' => $barangays[0][1],
                ]), 'Station Hub '.$name);
            $this->stations[$stationKey] = $station;

            $areaIds = [];

            foreach ($barangays as [$code, $barangay]) {
                $areaIds[] = Area::query()->firstOrCreate(
                    ['logistics_provider_id' => $provider->id, 'barangay_code' => $code],
                    [
                        'station_hub_id' => $station->id,
                        'name' => $barangay,
                        'municipality' => $cityName,
                        'city_municipality_code' => $cityCode,
                        'barangay_name' => $barangay,
                        'coverage_validated_at' => now(),
                        'is_active' => true,
                    ],
                )->id;
            }

            $this->rider($provider, $station, $stationKey, $cityName, $areaIds);
        }

        foreach (self::RATES as $zone => [$first, $additional]) {
            LogisticsShippingRate::query()->firstOrCreate(
                ['logistics_provider_id' => $provider->id, 'zone' => $zone],
                ['first_kg_minor' => $first, 'additional_kg_minor' => $additional],
            );
        }
    }

    /** @param  list<int>  $areaIds */
    private function rider(LogisticsProvider $provider, LogisticsHub $station, string $key, string $city, array $areaIds): void
    {
        $rider = $this->account(Rider::class, 'rider-'.$key, [
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'first_name' => 'Demo',
            'last_name' => str_replace('City of ', '', $city).' Rider',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $courier = Courier::query()->firstOrCreate(['rider_id' => $rider->id], [
            'logistics_provider_id' => $provider->id,
            'area_id' => $areaIds[0],
            'name' => $rider->fullName(),
            'phone' => $rider->contact_no,
            'service_area' => [$city],
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 20000,
            'is_available' => true,
        ]);

        // A rider linked to any barangay serves only linked barangays, so link
        // every barangay at the station: the rider handles pickups and deliveries.
        app(LogisticsHubService::class)->linkCourierToAreas($provider->id, $courier->id, $areaIds);
    }

    /**
     * One Courier record per demo company, so its transfer batches and
     * handoffs can be dispatched. Couriers are records, not accounts.
     */
    private function transferCourier(string $key, string $companyName): void
    {
        if (! TransferCourierService::available()) {
            return;
        }
        $index = array_search($key, array_keys(self::PROVIDERS), true);
        $name = str_replace('Express', 'Courier', $companyName);
        // TransferCourier is fully guarded, so it is filled with forceFill(), never mass assigned.
        $courier = TransferCourier::query()
            ->where('logistics_provider_id', $this->providers[$key]->id)
            ->where('name', $name)
            ->first();
        if ($courier === null) {
            $courier = new TransferCourier;
            $courier->forceFill([
                'logistics_provider_id' => $this->providers[$key]->id,
                'name' => $name,
                'contact_no' => sprintf('0917000%04d', 101 + (int) $index),
                'vehicle_type' => 'Van',
                'plate_number' => sprintf('DEMO %d', 101 + (int) $index),
                'status' => TransferCourier::ACTIVE,
            ])->save();
            $this->report['created'][] = 'Courier '.$courier->name;
        }
    }

    private function sellerWithProduct(string $slug, string $provider, string $station, string $name): void
    {
        $seller = $this->account(Seller::class, $slug, ['business_name' => $name] + self::addressAt($provider, $station, 0));

        // Enabling goes through the real eligibility check for the pickup barangay.
        app(SellerShippingProviderService::class)->enable($seller, $this->providers[$provider]);

        if (Product::query()->where('seller_id', $seller->id)->doesntExist()) {
            $product = Product::factory()->create([
                'seller_id' => $seller->id,
                'category_id' => $seller->line_of_business_id,
                'name' => $name.' Test Parcel',
                'slug' => self::SLUG_PREFIX.$slug.'-parcel',
                'description' => 'Demo product for walking through the delivery scenarios.',
                'price' => 500,
                'stock_quantity' => 50,
                'weight_grams' => 800,
                'status' => 'active',
            ]);

            $this->report['created'][] = 'Product '.$product->name;
        }
    }

    /** @param  array<string, string>  $address */
    private function buyer(string $slug, array $address): Buyer
    {
        return $this->account(Buyer::class, $slug, ['first_name' => 'Demo', 'last_name' => str($slug)->after('buyer-')->upper()->toString().' Buyer'] + $address);
    }

    private function buyerAccount(string $slug): Buyer
    {
        return Buyer::query()->where('email', self::email($slug))->firstOrFail();
    }

    private function unsupportedBuyer(): void
    {
        $buyer = $this->buyer('buyer-unsupported', [
            'address_mode' => 'manual',
            'province_code' => self::UNSUPPORTED_ADDRESS['province'],
            'province_name' => self::UNSUPPORTED_ADDRESS['province_name'],
            'municipality_code' => self::UNSUPPORTED_ADDRESS['municipality'],
            'municipality_name' => self::UNSUPPORTED_ADDRESS['municipality_name'],
            'barangay_code' => self::UNSUPPORTED_ADDRESS['barangay'],
            'barangay_name' => self::UNSUPPORTED_ADDRESS['barangay_name'],
            'street' => self::UNSUPPORTED_ADDRESS['street'],
            'postal_code' => self::UNSUPPORTED_ADDRESS['postal_code'],
        ]);

        if ($buyer->addresses()->exists()) {
            return;
        }

        $supported = self::addressAt('laguna', 'pila', 1);
        $addressBook = app(BuyerAddressBook::class);
        $addressBook->save($buyer, self::UNSUPPORTED_ADDRESS, asDefault: true);
        $addressBook->save($buyer, [
            'address_mode' => 'manual',
            'province' => $supported['province_code'],
            'province_name' => $supported['province_name'],
            'municipality' => $supported['municipality_code'],
            'municipality_name' => $supported['municipality_name'],
            'barangay' => $supported['barangay_code'],
            'barangay_name' => $supported['barangay_name'],
            'street' => $supported['street'],
            'postal_code' => $supported['postal_code'],
        ]);
    }

    private function link(LogisticsProvider $origin, LogisticsProvider $destination, Admin $admin): void
    {
        $link = LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', $destination->id)
            ->whereIn('status', [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE])
            ->first();

        if ($link?->isActive()) {
            return;
        }

        $partnerships = app(ProviderHandoffSchemaService::class);
        $link ??= $partnerships->requestLink($origin, $destination);

        if ($link->destination_consented_at === null) {
            $link = $partnerships->consent($link, $destination);
        }

        $partnerships->approve($link, $admin);
        $this->report['created'][] = 'Partnership '.$origin->business_name.' -> '.$destination->business_name;
    }

    private function connection(LogisticsHub $origin, LogisticsHub $destination, string $mode): void
    {
        $connection = TransportConnection::query()->firstOrCreate([
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'transport_mode' => $mode,
            'operating_provider_id' => $origin->logistics_provider_id,
        ], ['is_active' => true]);

        if ($connection->wasRecentlyCreated) {
            $this->report['created'][] = 'Connection '.$origin->name.' -> '.$destination->name.' ('.$mode.')';
        }
    }

    /**
     * Find a demo account by its stable slug, so changing the mailbox re-homes
     * the same account instead of colliding with it.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function account(string $model, string $slug, array $attributes): Model
    {
        $email = self::email($slug);
        $existing = $model::query()->where('email', 'like', '%'.self::SLUG_PREFIX.$slug.'@%')->first();

        if ($existing !== null) {
            if ($existing->email !== $email) {
                $existing->forceFill(['email' => $email])->save();
                $this->report['updated'][] = class_basename($model).' email -> '.$email;
            }

            return $existing;
        }

        $this->report['created'][] = class_basename($model).' '.$email;

        return $model::factory()->create(['email' => $email, 'password' => self::PASSWORD] + $attributes);
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function created(Model $model, string $label): Model
    {
        $this->report['created'][] = $label;

        return $model;
    }

    private function printSummary(): void
    {
        foreach ($this->report as $label => $lines) {
            if ($lines !== []) {
                $this->command?->line(strtoupper($label).' ('.count($lines).'):');
                $this->command?->line('  - '.implode("\n  - ", $lines));
            }
        }

        if ($this->report['created'] === [] && $this->report['updated'] === []) {
            $this->command?->info('Demo network already complete; nothing changed.');
        }

        $this->command?->info('Demo accounts (password: '.self::PASSWORD.'):');
        $this->command?->table(['Scenario', 'Buyer', 'Seller', 'Origin Logistics / Rider', 'Destination Logistics / Rider'], [
            ['1 Local', self::email('buyer-pila'), self::email('seller-pila'), self::email('logistics-laguna').' / '.self::email('rider-pila'), 'same'],
            ['2 Intra-province', self::email('buyer-pila'), self::email('seller-lumban'), self::email('logistics-laguna').' / '.self::email('rider-lumban'), 'same company / '.self::email('rider-pila')],
            ['3 Inter-province', self::email('buyer-qc'), self::email('seller-bulacan'), self::email('logistics-bulacan').' / '.self::email('rider-malolos'), self::email('logistics-metro').' / '.self::email('rider-qc')],
            ['4 Inter-island', self::email('buyer-davao'), self::email('seller-batangas'), self::email('logistics-batangas').' / '.self::email('rider-batangas-city'), self::email('logistics-davao').' / '.self::email('rider-davao-city')],
            ['5 Unsupported', self::email('buyer-unsupported'), self::email('seller-pila'), 'none: checkout blocks', 'none'],
        ]);

        if (! is_string(getenv(self::MAILBOX_ENV))) {
            $this->command?->warn('Demo emails are @zefanya.test, so mailed notices will not reach an inbox. Set '.self::MAILBOX_ENV.' to your own address and re-run to receive them.');
        }
    }

    private function printScenarioCheck(): void
    {
        $rows = $this->scenarioCheck();

        $this->command?->info('Checkout result per scenario:');
        $this->command?->table(['Scenario', 'Result', 'Route'], array_map(fn (array $row): array => [
            $row['scenario'],
            $row['available'] === $row['expected'] ? 'OK' : 'UNEXPECTED',
            $row['route'],
        ], $rows));

        foreach ($rows as $row) {
            if ($row['available'] !== $row['expected']) {
                $this->command?->error($row['scenario'].' did not quote as expected. Check that demo hubs, coverage and links are still active.');
            }
        }
    }
}
