<?php

namespace Database\Seeders;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\DeliveryOtpService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Seller\SellerShippingProviderService;
use Database\Seeders\Support\InProcessHttp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Throwable;

/**
 * Test parcels at every Logistics and Rider stage, inside the real
 * "Ninja Express" company, so each screen has something to act on.
 *
 * Adds to Ninja Express (never edits its existing hubs, riders or coverage):
 *   - Bay Station Hub (Dila) and two Los Baños barangays (Bambang, Tadlak)
 *   - four test riders, ROAD connections Bay <-> Los Baños
 *   - partnerships + connections with the demo Metro Manila and Bulacan
 *     companies from DeliveryScenarioSeeder (run automatically first)
 *   - one pending partnership request each way
 *
 * Buyers and sellers are new test accounts (@zefanya.test), so no real
 * customer gets an order. Juan Dela Cruz is not linked to the new barangays,
 * so automatic dispatch never hands him a test parcel.
 *
 * Every parcel is moved through the real HTTP routes in-process (Support\InProcessHttp) (the same
 * calls EndToEndLogisticsStabilizationTest makes), so custody, scan tokens,
 * rider capacity and notifications all follow the app's own rules. Mail goes
 * to the log while this runs. Each scenario is tagged in the buyer note
 * ("[ninja-playground:KEY]") and skipped if it already exists, so re-running
 * only fills in what is missing.
 *
 * Dev-only. Run:  php artisan db:seed --class=NinjaExpressPlaygroundSeeder
 */
class NinjaExpressPlaygroundSeeder extends Seeder
{
    public const PROVIDER_NAME = 'Ninja Express';

    public const PREFIX = 'zef-ninja-';

    public const PASSWORD = DeliveryScenarioSeeder::PASSWORD;

    private const MARKER = '[ninja-playground:%s]';

    /** Real PSGC codes already used by addresses in this database. */
    private const LOS_BANOS = ['043411000', 'Los Baños'];

    private const LB_BAMBANG = ['043411003', 'Bambang'];

    private const LB_TADLAK = ['043411013', 'Tadlak'];

    private const BAY = ['043402000', 'Bay'];

    private const BAY_DILA = ['043402003', 'Dila'];

    /** Riders: slug => [first, last, station key, barangays]. */
    private const RIDERS = [
        'rider-pickup' => ['Pedro', 'Pickup', 'lb'],
        'rider-delivery' => ['Dina', 'Delivery', 'lb'],
        'rider-field' => ['Felix', 'Field', 'lb'],
        'rider-standby-1' => ['Sam', 'Standby', 'lb'],
        'rider-standby-2' => ['Rita', 'Reserve', 'lb'],
        'rider-bay' => ['Bayani', 'Bay', 'bay'],
    ];

    private InProcessHttp $http;

    private LogisticsProvider $ninja;

    private LogisticsProvider $metro;

    private LogisticsProvider $bulacan;

    /** @var array<string, LogisticsHub> */
    private array $hubs = [];

    /** @var array<string, Courier> */
    private array $couriers = [];

    /** @var array<string, Model> */
    private array $accounts = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('NinjaExpressPlaygroundSeeder refuses to run in production.');

            return;
        }

        // Demo batches are created and dispatched by script, without a carrier
        // Rider. Real batches still need one (config transfers.require_transport).
        config(['transfers.require_transport' => false]);

        $ninja = LogisticsProvider::query()->where('business_name', self::PROVIDER_NAME)->first();
        if ($ninja === null) {
            $this->command?->error('No Logistics company named "'.self::PROVIDER_NAME.'" exists.');

            return;
        }
        $this->ninja = $ninja;

        $this->http = new InProcessHttp;
        $this->call(DeliveryScenarioSeeder::class);

        $this->metro = $this->demoProvider('logistics-metro');
        $this->bulacan = $this->demoProvider('logistics-bulacan');

        $this->network();
        $this->runScenarios();
        $this->report();
    }

    // ───────────────────────── network ─────────────────────────

    private function network(): void
    {
        $provinceHub = LogisticsHub::query()->where('logistics_provider_id', $this->ninja->id)->liveProvince()->first()
            ?? throw new RuntimeException('Ninja Express has no live Province Hub.');

        $this->hubs['lb'] = LogisticsHub::query()
            ->where('logistics_provider_id', $this->ninja->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('city_municipality_code', self::LOS_BANOS[0])
            ->firstOr(fn () => throw new RuntimeException('Ninja Express has no Los Baños Station Hub.'));

        $this->hubs['bay'] = LogisticsHub::query()->firstOrCreate([
            'logistics_provider_id' => $this->ninja->id,
            'hub_type' => LogisticsHub::STATION,
            'city_municipality_code' => self::BAY[0],
        ], [
            'parent_hub_id' => $provinceHub->id,
            'name' => 'Bay Station Hub',
            'municipality' => self::BAY[1],
            'barangay_code' => self::BAY_DILA[0],
            'barangay_name' => self::BAY_DILA[1],
            'region_code' => $provinceHub->region_code,
            'region_name' => $provinceHub->region_name,
            'province_code' => $provinceHub->province_code,
            'province_name' => $provinceHub->province_name,
            'street' => '1 Test Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);

        $areas = [
            'lb' => [$this->area('lb', self::LOS_BANOS, self::LB_BAMBANG), $this->area('lb', self::LOS_BANOS, self::LB_TADLAK)],
            'bay' => [$this->area('bay', self::BAY, self::BAY_DILA)],
        ];

        foreach (self::RIDERS as $slug => [$first, $last, $station]) {
            $this->couriers[$slug] = $this->rider($slug, $first, $last, $this->hubs[$station], $areas[$station]);
        }

        $this->connection($this->hubs['bay'], $this->hubs['lb']);
        $this->connection($this->hubs['lb'], $this->hubs['bay']);

        $admin = Admin::query()->orderBy('id')->firstOrFail();
        foreach ([[$this->ninja, $this->metro], [$this->metro, $this->ninja], [$this->bulacan, $this->ninja], [$this->ninja, $this->bulacan]] as [$from, $to]) {
            $this->activeLink($from, $to, $admin);
        }

        $this->connection($this->hubs['lb'], $this->demoStation($this->metro, '137404000'));
        $this->connection($this->demoStation($this->bulacan, '031410000'), $this->hubs['lb']);

        $this->pendingLink($this->ninja, $this->demoProvider('logistics-batangas'));
        $this->pendingLink($this->demoProvider('logistics-davao'), $this->ninja);

        $this->accounts['seller-lb'] = $this->seller('seller-lb', 'Ninja Test Shop Los Baños', self::LOS_BANOS, self::LB_BAMBANG);
        $this->accounts['seller-bay'] = $this->seller('seller-bay', 'Ninja Test Shop Bay', self::BAY, self::BAY_DILA);
        $this->accounts['seller-bulacan'] = Seller::query()->where('email', DeliveryScenarioSeeder::email('seller-bulacan'))->firstOrFail();
        $this->products['seller-bulacan'] = Product::query()->where('seller_id', $this->accounts['seller-bulacan']->id)->firstOrFail();

        $this->accounts['buyer-lb'] = $this->buyer('buyer-lb', 'Los Baños', [
            'province_code' => '043400000', 'province_name' => 'Laguna',
            'municipality_code' => self::LOS_BANOS[0], 'municipality_name' => self::LOS_BANOS[1],
            'barangay_code' => self::LB_TADLAK[0], 'barangay_name' => self::LB_TADLAK[1], 'postal_code' => '4030',
        ]);
        $this->accounts['buyer-qc'] = $this->buyer('buyer-qc', 'Quezon City', [
            'province_code' => '130000000', 'province_name' => 'Metro Manila',
            'municipality_code' => '137404000', 'municipality_name' => 'Quezon City',
            'barangay_code' => '137404001', 'barangay_name' => 'Alicia', 'postal_code' => '1105',
        ]);
    }

    /** @param  array{0: string, 1: string}  $city  @param  array{0: string, 1: string}  $barangay */
    private function area(string $station, array $city, array $barangay): int
    {
        return Area::query()->firstOrCreate(
            ['logistics_provider_id' => $this->ninja->id, 'barangay_code' => $barangay[0]],
            [
                'station_hub_id' => $this->hubs[$station]->id,
                'name' => $barangay[1],
                'municipality' => $city[1],
                'city_municipality_code' => $city[0],
                'barangay_name' => $barangay[1],
                'coverage_validated_at' => now(),
                'is_active' => true,
            ],
        )->id;
    }

    /** @param  list<int>  $areaIds */
    private function rider(string $slug, string $first, string $last, LogisticsHub $station, array $areaIds): Courier
    {
        $rider = $this->account(Rider::class, $slug, [
            'logistics_provider_id' => $this->ninja->id,
            'station_hub_id' => $station->id,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $courier = Courier::query()->firstOrCreate(['rider_id' => $rider->id], [
            'logistics_provider_id' => $this->ninja->id,
            'area_id' => $areaIds[0],
            'name' => $rider->fullName(),
            'phone' => $rider->contact_no,
            'service_area' => [$station->municipality],
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 20000,
            'is_available' => true,
        ]);
        app(LogisticsHubService::class)->linkCourierToAreas($this->ninja->id, $courier->id, $areaIds);

        return $courier;
    }

    private function connection(LogisticsHub $origin, LogisticsHub $destination): void
    {
        TransportConnection::query()->firstOrCreate([
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'transport_mode' => TransportConnection::ROAD,
            'operating_provider_id' => $origin->logistics_provider_id,
        ], ['is_active' => true]);
    }

    private function activeLink(LogisticsProvider $origin, LogisticsProvider $destination, Admin $admin): void
    {
        $link = $this->openLink($origin, $destination);
        if ($link?->isActive()) {
            return;
        }

        $service = app(ProviderHandoffSchemaService::class);
        $link ??= $service->requestLink($origin, $destination);
        if ($link->destination_consented_at === null) {
            $link = $service->consent($link, $destination);
        }
        $service->approve($link, $admin);
    }

    private function pendingLink(LogisticsProvider $origin, LogisticsProvider $destination): void
    {
        if ($this->openLink($origin, $destination) === null) {
            try {
                app(ProviderHandoffSchemaService::class)->requestLink($origin, $destination);
            } catch (Throwable $e) {
                $this->results[] = ['partnership', $origin->business_name.' -> '.$destination->business_name, 'SKIPPED: '.$e->getMessage()];
            }
        }
    }

    private function openLink(LogisticsProvider $origin, LogisticsProvider $destination): ?LogisticsProviderLink
    {
        return LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', $destination->id)
            ->whereIn('status', [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE])
            ->first();
    }

    /** @param  array{0: string, 1: string}  $city  @param  array{0: string, 1: string}  $barangay */
    private function seller(string $slug, string $name, array $city, array $barangay): Seller
    {
        $seller = $this->account(Seller::class, $slug, [
            'business_name' => $name,
            'address_mode' => 'manual',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'municipality_code' => $city[0],
            'municipality_name' => $city[1],
            'barangay_code' => $barangay[0],
            'barangay_name' => $barangay[1],
            'street' => '1 Test Street',
            'postal_code' => '4030',
        ]);
        app(SellerShippingProviderService::class)->enable($seller, $this->ninja);

        $this->products[$slug] = Product::query()->where('seller_id', $seller->id)->first()
            ?? Product::factory()->create([
                'seller_id' => $seller->id,
                'category_id' => $seller->line_of_business_id,
                'name' => $name.' Test Parcel',
                'slug' => self::PREFIX.$slug.'-parcel',
                'description' => 'Test product for the Ninja Express playground.',
                'price' => 500,
                'stock_quantity' => 500,
                'weight_grams' => 800,
                'status' => 'active',
            ]);

        return $seller;
    }

    /** @param  array<string, string>  $address */
    private function buyer(string $slug, string $label, array $address): Buyer
    {
        return $this->account(Buyer::class, $slug, ['first_name' => 'Ninja Test', 'last_name' => $label.' Buyer', 'address_mode' => 'manual', 'street' => '1 Test Street'] + $address);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function account(string $model, string $slug, array $attributes): Model
    {
        $email = self::PREFIX.$slug.'@zefanya.test';

        return $model::query()->where('email', $email)->first()
            ?? $model::factory()->create(['email' => $email, 'password' => self::PASSWORD] + $attributes);
    }

    private function demoProvider(string $slug): LogisticsProvider
    {
        return LogisticsProvider::query()->where('email', DeliveryScenarioSeeder::email($slug))->firstOrFail();
    }

    private function demoStation(LogisticsProvider $provider, string $cityCode): LogisticsHub
    {
        return LogisticsHub::query()->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->where('city_municipality_code', $cityCode)->firstOrFail();
    }

    // ───────────────────────── scenarios ─────────────────────────

    private function runScenarios(): void
    {
        $lb = fn (): SellerOrder => $this->order('seller-lb', 'buyer-lb', $this->ninja);
        $bay = fn (): SellerOrder => $this->order('seller-bay', 'buyer-lb', $this->ninja);
        $toQc = fn (): SellerOrder => $this->order('seller-lb', 'buyer-qc', $this->ninja);
        $fromBulacan = fn (): SellerOrder => $this->order('seller-bulacan', 'buyer-lb', $this->bulacan);

        // Order matters: each rider may hold 3 active jobs, and sorting a parcel
        // makes the app auto-reserve a delivery rider (fewest jobs, then lowest
        // id). Fixed rider loads are filled first; Felix then collects every
        // other pickup one at a time; auto-reserved sorted parcels come last
        // so they land on the standby riders.
        $scenarios = [
            // Local Los Baños -> Los Baños.
            'L01' => ['Awaiting seller (PLACED)', $lb, fn ($p) => null],
            'L02' => ['Ready for pickup, Pedro reserved', $lb, fn ($p) => $this->ready($p, 'rider-pickup')],
            'L03' => ['Ready for pickup, Pedro reserved (try failed pickup)', $lb, fn ($p) => $this->ready($p, 'rider-pickup')],
            'L04' => ['Picked up by Pedro, awaiting station receipt', $lb, fn ($p) => $this->pickedUp($p, 'rider-pickup')],
            'L08' => ['Out for delivery with Dina (send OTP, deliver)', $lb, fn ($p) => $this->outForDelivery($p, 'rider-delivery')],
            'L09' => ['Out for delivery with Dina (mark failed)', $lb, fn ($p) => $this->outForDelivery($p, 'rider-delivery')],
            // Not Dina: a parcel auto-reserved as a rider's 3rd job cannot then be
            // confirmed to that same rider (the limit check counts the reservation).
            'L07' => ['Assigned to Sam, not started', $lb, fn ($p) => $this->assigned($p, 'rider-standby-1')],
            'L10' => ['Delivered, COD collected by Felix (remit / return)', $lb, fn ($p) => $this->delivered($p, 'rider-field')],
            'L11' => ['Completed by buyer (history, earnings)', $lb, fn ($p) => $this->completed($p, 'rider-field')],
            'L12' => ['Return approved, needs a collection rider', $lb, fn ($p) => $this->returnApproved($p, 'rider-field')],
            'L14' => ['Delivery failed with Felix, receive back at station', $lb, fn ($p) => $this->failed($p, 'rider-field')],
            'L13' => ['Exception: reported missing at Los Baños', $lb, fn ($p) => $this->exception($p, 'rider-field')],
            'L05' => ['Sorted at Los Baños, delivery rider auto-reserved', $lb, fn ($p) => $this->atStation($p, 'rider-field')],
            'L06' => ['Sorted at Los Baños, delivery rider auto-reserved', $lb, fn ($p) => $this->atStation($p, 'rider-field')],
            // Bay -> Los Baños: internal manifests.
            'M01' => ['At Bay Station, needs a manifest', $bay, fn ($p) => $this->atStation($p, 'rider-bay')],
            'M02' => ['At Bay Station, needs a manifest', $bay, fn ($p) => $this->atStation($p, 'rider-bay')],
            'M03' => ['On a draft manifest Bay -> Los Baños', $bay, fn ($p) => $this->draftManifest($p)],
            'M04' => ['On a dispatched manifest, receive at Los Baños', $bay, fn ($p) => $this->dispatchedManifest($p)],
            // Los Baños -> Quezon City: Ninja hands over to Demo Metro Manila Express.
            'H01' => ['Outgoing: at Los Baños, request a handoff to Metro', $toQc, fn ($p) => $this->atStation($p, 'rider-field')],
            'H02' => ['Outgoing: handoff requested, waiting for Metro', $toQc, fn ($p) => $this->handoffRequested($p, 'rider-field', $this->ninja)],
            'H03' => ['Outgoing: Metro accepted, dispatch from Los Baños', $toQc, fn ($p) => $this->handoffAccepted($p, 'rider-field', $this->ninja, $this->metro)],
            'H04' => ['Outgoing: dispatched, waiting for Metro to receive', $toQc, fn ($p) => $this->handoffDispatched($p, 'rider-field', $this->ninja, $this->metro)],
            // Malolos -> Los Baños: Demo Bulacan Express hands over to Ninja.
            'H05' => ['Incoming from Bulacan: accept the handoff', $fromBulacan, fn ($p) => $this->handoffRequested($p, null, $this->bulacan)],
            'H06' => ['Incoming from Bulacan: receive at Los Baños', $fromBulacan, fn ($p) => $this->handoffDispatched($p, null, $this->bulacan, $this->ninja)],
        ];

        foreach ($scenarios as $key => [$label, $create, $advance]) {
            $marker = sprintf(self::MARKER, $key);
            $existing = SellerOrder::query()->where('buyer_note', $marker)->first();
            if ($existing !== null) {
                $this->results[] = [$key, $label, 'exists: '.$existing->tracking_number.' ('.$existing->status->value.')'];

                continue;
            }

            try {
                $this->currentMarker = $marker;
                $parcel = $create();
                $advance($parcel);
                $parcel->refresh();
                $this->results[] = [$key, $label, $parcel->tracking_number.' ('.$parcel->status->value.')'];
            } catch (Throwable $e) {
                $this->results[] = [$key, $label, 'FAILED: '.$e->getMessage()];
            }
        }
    }

    private string $currentMarker = '';

    private function order(string $sellerSlug, string $buyerSlug, LogisticsProvider $carrier): SellerOrder
    {
        $buyer = $this->accounts[$buyerSlug];
        $seller = $this->accounts[$sellerSlug];
        $cart = CartItem::query()->create(['buyer_id' => $buyer->id, 'product_id' => $this->products[$sellerSlug]->id, 'quantity' => 1]);

        $this->http->as($buyer, 'buyer');
        $this->http->putSession(CheckoutController::SESSION_KEY, [$cart->id]);
        $this->http->send('post', route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $carrier->id],
            'notes' => [$seller->id => $this->currentMarker],
        ], keepSession: true);

        return SellerOrder::query()->where('buyer_note', $this->currentMarker)->latest('id')->firstOrFail();
    }

    private function sellerAdvance(SellerOrder $parcel): void
    {
        $this->http->as($parcel->seller, 'seller');
        $this->http->send('post', route('seller.orders.accept', $parcel));
        $this->http->send('post', route('seller.orders.pack', $parcel));
        $this->http->send('post', route('seller.orders.markReady', $parcel));
    }

    private function ready(SellerOrder $parcel, string $rider): void
    {
        $this->sellerAdvance($parcel);
        $provider = LogisticsProvider::query()->findOrFail($parcel->fresh()->carrier_provider_id);
        $courier = $this->courier($rider);
        // Marking ready triggers the app's automatic dispatch, which may have
        // picked another rider; move the job to the planned one.
        $current = $parcel->fresh()->courier_id;
        if ((int) $current !== $courier->id) {
            $this->http->send('post', route('logistics.incoming-parcels.assignPickupRider', $parcel), $current === null
                ? ['courier_id' => $courier->id, 'reason' => 'Playground pickup']
                : ['courier_id' => $courier->id, 'reason' => 'Playground pickup', 'reassign' => 1, 'expected_courier_id' => $current],
                as: [$provider, 'logistics']);
        }
    }

    private function pickedUp(SellerOrder $parcel, string $rider): void
    {
        $this->ready($parcel, $rider);
        $this->http->send('post', route('rider.pickups.confirm', $parcel), ['verification_token' => $this->riderToken($rider, $parcel, 'confirm_pickup')], as: [$this->courier($rider)->rider, 'rider']);
    }

    private function atStation(SellerOrder $parcel, ?string $rider): void
    {
        $this->pickedUp($parcel, $rider ?? $this->demoRiderFor($parcel));
        $provider = LogisticsProvider::query()->findOrFail($parcel->fresh()->carrier_provider_id);
        $this->http->send('post', route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $this->logisticsToken($provider, $parcel, 'confirm_station_receipt')], as: [$provider, 'logistics']);
    }

    private function assigned(SellerOrder $parcel, string $rider): void
    {
        $this->atStation($parcel, 'rider-field');
        $planned = $this->courier($rider)->id;
        // Sorting auto-reserves a delivery rider; move it to the planned one first.
        $reserved = $parcel->fresh()->delivery_courier_id;
        if ($reserved !== null && (int) $reserved !== $planned) {
            $this->http->send('post', route('logistics.delivery-assignments.reassign', $parcel), ['courier_id' => $planned, 'reason' => 'Playground delivery', 'expected_courier_id' => $reserved], as: [$this->ninja, 'logistics']);
        }
        if ($parcel->fresh()->status->value === 'SORTED') {
            $this->http->send('post', route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $planned, 'reason' => 'Playground delivery'], as: [$this->ninja, 'logistics']);
        }
    }

    private function outForDelivery(SellerOrder $parcel, string $rider): void
    {
        $this->assigned($parcel, $rider);
        $this->http->send('post', route('rider.deliveries.start', $parcel), ['verification_token' => $this->riderToken($rider, $parcel, 'start_delivery')], as: [$this->courier($rider)->rider, 'rider']);
    }

    private function delivered(SellerOrder $parcel, string $rider): void
    {
        $this->outForDelivery($parcel, $rider);
        $riderAccount = $this->courier($rider)->rider;
        $this->http->send('post', route('rider.deliveries.otp', $parcel), ['verification_token' => $this->riderToken($rider, $parcel, 'proof_of_delivery')], as: [$riderAccount, 'rider']);
        $code = app(DeliveryOtpService::class)->activeCodeFor($parcel->fresh())['code'] ?? throw new RuntimeException('No delivery code was issued.');
        $payment = $parcel->fresh()->payment;
        $this->http->send('post', route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $this->riderToken($rider, $parcel, 'proof_of_delivery'),
            'delivery_otp' => $code,
            'proof_photo' => UploadedFile::fake()->image('proof.jpg', 640, 480),
            'received_by' => 'Buyer',
            'cod_amount' => $payment ? number_format($payment->amount_minor / 100, 2, '.', '') : null,
        ], as: [$riderAccount, 'rider']);
    }

    private function completed(SellerOrder $parcel, string $rider): void
    {
        $this->delivered($parcel, $rider);
        $this->http->send('post', route('buyer.orders.confirmReceived', $parcel->order), [], as: [$parcel->order->buyer, 'buyer']);
    }

    private function returnApproved(SellerOrder $parcel, string $rider): void
    {
        $this->delivered($parcel, $rider);
        $this->http->send('post', route('buyer.orders.requestReturn', [$parcel->order, $parcel]), [
            'reason' => ParcelReturnService::BUYER_REASONS[0],
            'description' => 'Playground return: the box arrived crushed.',
            'photos' => [UploadedFile::fake()->image('damage.jpg', 640, 480)],
        ], as: [$parcel->order->buyer, 'buyer']);
        $return = ParcelReturn::query()->where('seller_order_id', $parcel->id)->latest('id')->firstOrFail();
        $this->http->send('post', route('seller.returns.approve', $return), [], as: [$parcel->seller, 'seller']);
    }

    private function exception(SellerOrder $parcel, string $rider): void
    {
        $this->atStation($parcel, $rider);
        $this->http->send('post', route('logistics.exceptions.store', $parcel), [
            'type' => 'missing',
            'reason' => 'Playground: not found on the shelf',
            'description' => 'Station staff could not find this parcel during the evening count.',
        ], as: [$this->ninja, 'logistics']);
    }

    private function failed(SellerOrder $parcel, string $rider): void
    {
        $this->outForDelivery($parcel, $rider);
        $this->http->send('post', route('rider.deliveries.failed', $parcel), [
            'verification_token' => $this->riderToken($rider, $parcel, 'mark_failed'),
            'failure_reason' => 'recipient_unavailable',
            'failure_notes' => 'Playground: nobody home.',
        ], as: [$this->courier($rider)->rider, 'rider']);
    }

    private function draftManifest(SellerOrder $parcel): Manifest
    {
        $this->atStation($parcel, 'rider-bay');
        $this->http->as($this->ninja, 'logistics');
        $this->http->send('post', route('logistics.manifests.store'), ['origin_hub_id' => $this->hubs['bay']->id, 'destination_hub_id' => $this->hubs['lb']->id]);
        $manifest = Manifest::query()->where('logistics_provider_id', $this->ninja->id)->latest('id')->firstOrFail();
        $this->http->send('post', route('logistics.manifests.items.store', $manifest), ['seller_order_id' => $parcel->id]);

        return $manifest;
    }

    private function dispatchedManifest(SellerOrder $parcel): void
    {
        $manifest = $this->draftManifest($parcel);
        $item = $manifest->items()->where('seller_order_id', $parcel->id)->firstOrFail();
        $scan = app(ManifestService::class)->verifyScan($manifest, $this->ninja, $parcel->fresh()->tracking_number, 'dispatch');
        $this->http->send('post', route('logistics.manifests.dispatch', $manifest), ['tokens' => [$item->id => $scan['verification_token']]], as: [$this->ninja, 'logistics']);
    }

    private function handoffRequested(SellerOrder $parcel, ?string $rider, LogisticsProvider $origin): ParcelHandoff
    {
        $this->atStation($parcel, $rider);
        $this->http->send('post', route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id], as: [$origin, 'logistics']);

        return ParcelHandoff::query()->where('seller_order_id', $parcel->id)->latest('id')->firstOrFail();
    }

    private function handoffAccepted(SellerOrder $parcel, ?string $rider, LogisticsProvider $origin, LogisticsProvider $destination): ParcelHandoff
    {
        $handoff = $this->handoffRequested($parcel, $rider, $origin);
        $this->http->send('post', route('logistics.handoffs.accept', $handoff), [], as: [$destination, 'logistics']);

        return $handoff;
    }

    private function handoffDispatched(SellerOrder $parcel, ?string $rider, LogisticsProvider $origin, LogisticsProvider $destination): void
    {
        $handoff = $this->handoffAccepted($parcel, $rider, $origin, $destination);
        $scan = app(ParcelHandoffService::class)->verifyScan($handoff->fresh(), $origin, $parcel->fresh()->tracking_number, 'dispatch');
        $this->http->send('post', route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $scan['verification_token']], as: [$origin, 'logistics']);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function courier(string $slug): Courier
    {
        return $this->couriers[$slug] ?? Courier::query()->findOrFail((int) $slug);
    }

    /** The demo Bulacan rider collects Bulacan-origin parcels. */
    private function demoRiderFor(SellerOrder $parcel): string
    {
        $rider = Rider::query()->where('email', DeliveryScenarioSeeder::email('rider-malolos'))->firstOrFail();

        return (string) Courier::query()->where('rider_id', $rider->id)->value('id');
    }

    private function riderToken(string $rider, SellerOrder $parcel, string $action): string
    {
        $response = $this->http->send('postJson', route('rider.scan.verify'), ['tracking_number' => $parcel->fresh()->tracking_number], as: [$this->courier($rider)->rider, 'rider']);

        return $this->token($response, $action);
    }

    private function logisticsToken(LogisticsProvider $provider, SellerOrder $parcel, string $action): string
    {
        $response = $this->http->send('postJson', route('logistics.scan.verify'), ['tracking_number' => $parcel->fresh()->tracking_number], as: [$provider, 'logistics']);

        return $this->token($response, $action);
    }

    private function token(TestResponse $response, string $action): string
    {
        foreach ((array) $response->json('actions') as $candidate) {
            if (($candidate['key'] ?? null) === $action && filled($candidate['verification_token'] ?? null)) {
                return $candidate['verification_token'];
            }
        }

        throw new RuntimeException('Scan offered no "'.$action.'" action (got: '.implode(', ', array_column((array) $response->json('actions'), 'key')).').');
    }

    private function report(): void
    {
        $this->command?->info('Ninja Express playground (test password: '.self::PASSWORD.'):');
        $this->command?->table(['Key', 'Stage', 'Parcel'], $this->results);
        $this->command?->table(['Role', 'Login'], [
            ['Logistics', $this->ninja->email.' (your existing Ninja Express login)'],
            ['Rider: pickups', self::PREFIX.'rider-pickup@zefanya.test'],
            ['Rider: deliveries', self::PREFIX.'rider-delivery@zefanya.test'],
            ['Rider: delivered / failed / returns', self::PREFIX.'rider-field@zefanya.test'],
            ['Rider: assigned job + auto-reserved deliveries', self::PREFIX.'rider-standby-1@zefanya.test / '.self::PREFIX.'rider-standby-2@zefanya.test'],
            ['Rider: Bay Station', self::PREFIX.'rider-bay@zefanya.test'],
            ['Seller (Los Baños / Bay)', self::PREFIX.'seller-lb@zefanya.test / '.self::PREFIX.'seller-bay@zefanya.test'],
            ['Buyer (Los Baños / Quezon City)', self::PREFIX.'buyer-lb@zefanya.test / '.self::PREFIX.'buyer-qc@zefanya.test'],
            ['Partner companies', DeliveryScenarioSeeder::email('logistics-metro').' / '.DeliveryScenarioSeeder::email('logistics-bulacan')],
        ]);
    }
}
