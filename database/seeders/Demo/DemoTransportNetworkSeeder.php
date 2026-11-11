<?php

namespace Database\Seeders\Demo;

use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransportConnection;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Logistics\TransferCourierService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operational network on top of the coverage: station <-> Province Hub ROAD
 * connections inside every company, partnerships and Province Hub connections
 * between neighbouring companies, and the transport Couriers (personnel
 * records with no login) each company dispatches transfer batches with.
 *
 * Partnerships follow the existing request -> consent -> Admin approval flow,
 * and connections go through the model's own validation, so nothing here
 * bypasses scan security or provider-province rules. The existing direct
 * connections stay the shortest routes, so the five original scenarios keep
 * their paths.
 */
class DemoTransportNetworkSeeder extends Seeder
{
    /**
     * Neighbouring companies and how a parcel physically moves between their
     * Province Hubs: [first, second, mode]. Each pair is linked both ways.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const NEIGHBOURS = [
        ['bulacan', 'metro', TransportConnection::ROAD],
        ['metro', 'laguna', TransportConnection::ROAD],
        ['laguna', 'batangas', TransportConnection::ROAD],
        ['batangas', 'cebu', TransportConnection::SEA],
        ['cebu', 'davao', TransportConnection::SEA],
        ['metro', 'cebu', TransportConnection::AIR],
        ['metro', 'davao', TransportConnection::AIR],
    ];

    /** @var array{connections: int, partnerships: int, couriers: int} */
    public array $created = ['connections' => 0, 'partnerships' => 0, 'couriers' => 0];

    /**
     * Pairs left alone because a partnership request on a real company is still pending.
     * Only an Admin approving it through the normal flow can open that route.
     *
     * @var list<string>
     */
    public array $gaps = [];

    public function run(): void
    {
        $admin = DemoNetwork::admin() ?? throw new RuntimeException('An Admin must exist to approve provider partnerships. Run AdminSeeder first.');

        DB::transaction(function () use ($admin): void {
            $keys = DemoNetwork::areaKeys();
            $providers = [];
            foreach ($keys as $key) {
                $providers[$key] = DemoNetwork::provider($key) ?? throw new RuntimeException("Provider for {$key} is missing; run DemoCoverageSeeder first.");
                $this->spokes($providers[$key]);
                $this->couriers($key, $providers[$key]);
            }

            // A limited run still links its areas to their neighbours; the neighbours are only read.
            $pairs = array_values(array_filter(self::NEIGHBOURS, fn (array $pair): bool => in_array($pair[0], $keys, true) || in_array($pair[1], $keys, true)));
            foreach ($pairs as [$first, $second]) {
                foreach ([$first, $second] as $key) {
                    $providers[$key] ??= DemoNetwork::provider($key) ?? throw new RuntimeException("Provider for {$key} is missing; run DemoCoverageSeeder first.");
                }
            }

            $open = [];
            foreach ($pairs as $index => [$first, $second]) {
                $open[$index] = $this->linkPair($first, $providers[$first], $second, $providers[$second], $admin);
            }

            foreach ($pairs as $index => [$first, $second, $mode]) {
                if ($open[$index]) {
                    $this->connect($this->provinceHub($providers[$first]), $this->provinceHub($providers[$second]), $mode);
                    $this->connect($this->provinceHub($providers[$second]), $this->provinceHub($providers[$first]), $mode);
                }
            }
        });
    }

    private function provinceHub(LogisticsProvider $provider): LogisticsHub
    {
        return LogisticsHub::query()->where('logistics_provider_id', $provider->id)->liveProvince()->firstOrFail();
    }

    /** Every active station reaches its Province Hub and back by road. */
    private function spokes(LogisticsProvider $provider): void
    {
        $province = $this->provinceHub($provider);

        LogisticsHub::query()->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->where('is_active', true)
            ->orderBy('id')->get()
            ->each(function (LogisticsHub $station) use ($province): void {
                $this->connect($station, $province, TransportConnection::ROAD);
                $this->connect($province, $station, TransportConnection::ROAD);
            });
    }

    private function connect(LogisticsHub $origin, LogisticsHub $destination, string $mode): void
    {
        $connection = TransportConnection::query()->firstOrCreate([
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'transport_mode' => $mode,
            'operating_provider_id' => $origin->logistics_provider_id,
        ], ['is_active' => true]);

        if ($connection->wasRecentlyCreated) {
            $this->created['connections']++;
        }
    }

    /**
     * Link both directions. A real company's request that is still pending is somebody's
     * pending decision: it is neither approved nor duplicated here, and the pair is skipped.
     */
    private function linkPair(string $firstKey, LogisticsProvider $first, string $secondKey, LogisticsProvider $second, Admin $admin): bool
    {
        $real = DemoNetwork::demoTag($first, $firstKey) !== '' || DemoNetwork::demoTag($second, $secondKey) !== '';
        $pending = $real && LogisticsProviderLink::query()
            ->where('status', LogisticsProviderLink::PENDING)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('origin_provider_id', $first->id)->where('destination_provider_id', $second->id))
                ->orWhere(fn ($q) => $q->where('origin_provider_id', $second->id)->where('destination_provider_id', $first->id)))
            ->exists();

        if ($pending) {
            $this->gaps[] = "{$first->business_name} <-> {$second->business_name}: a partnership request is pending Admin approval; no route was created.";

            return false;
        }

        $this->link($first, $second, $admin);
        $this->link($second, $first, $admin);

        return true;
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
        $this->created['partnerships']++;
    }

    /**
     * Transport Couriers: records Logistics manages, with no login, token,
     * COD, earnings or payout. DeliveryScenarioSeeder already adds one van
     * per original company; two more per company are added here. A company
     * that is not the area's own demo company (Ninja Express in Laguna) gets
     * one Courier per six Stations, at least two, with plates and contact
     * numbers of its own so they never repeat another company's.
     */
    private function couriers(string $key, LogisticsProvider $provider): void
    {
        if (! TransferCourierService::available()) {
            return;
        }

        $code = DemoNetwork::CODES[$key];
        $index = array_search($key, DemoNetwork::allAreaKeys(), true);
        $tagged = DemoNetwork::demoTag($provider, $key) !== '';
        $wanted = 2;

        if ($tagged) {
            $stations = LogisticsHub::query()->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::STATION)->where('is_active', true)->count();
            $wanted = max(2, (int) ceil($stations / 6));
        }

        for ($number = 1; $number <= $wanted; $number++) {
            $vehicle = $number % 2 === 1 ? 'Van' : 'Truck';
            $seed = ($index + 1) * 10 + $number;
            [$first, $last] = DemoNetwork::personName($seed);
            $this->courier(
                $provider,
                $first.' '.$last,
                $tagged ? DemoNetwork::phone(6, 1000 + $provider->id * 10 + $number) : DemoNetwork::phone(6, 100 + $seed),
                $vehicle,
                $tagged ? sprintf('DEMO P%d-%d', $provider->id, $number) : sprintf('D%s %d%02d', strtoupper(substr($code, 0, 2)), $number, $index + 1),
            );
        }

        // The sixth company has no original courier from DeliveryScenarioSeeder.
        if ($key === 'cebu') {
            $this->courier($provider, 'Demo Cebu Courier', DemoNetwork::phone(6, 99), 'Van', 'DEMO 106');
        }
    }

    /** TransferCourier is fully guarded, so it is filled with forceFill(), never mass assigned. */
    private function courier(LogisticsProvider $provider, string $name, string $contact, string $vehicle, string $plate): void
    {
        if (TransferCourier::query()->where('logistics_provider_id', $provider->id)->where('name', $name)->exists()) {
            return;
        }

        (new TransferCourier)->forceFill([
            'logistics_provider_id' => $provider->id,
            'name' => $name,
            'contact_no' => $contact,
            'vehicle_type' => $vehicle,
            'plate_number' => $plate,
            'status' => TransferCourier::ACTIVE,
        ])->save();
        $this->created['couriers']++;
    }
}
