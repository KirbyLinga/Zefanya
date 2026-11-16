<?php

namespace Database\Seeders\Demo;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Services\Logistics\LogisticsHubService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rider coverage: every covered barangay gets an approved, verified, online
 * Rider of its own Station Hub, and no demo Rider holds more than three barangays.
 *
 * For each station the barangays that no eligible Rider already serves are
 * sorted by PSGC code and cut into groups of three, one demo Rider per group,
 * so a station ends with at least CEIL(uncovered barangays / 3) new Riders.
 * "Eligible" is Courier::eligibleForAssignment(): approved or active, online,
 * email verified, in an operational Station Hub of the same company. Riders are
 * linked through LogisticsHubService::linkCourierToAreas(), which enforces
 * approval, station and provider ownership.
 *
 * A demo Rider belongs to exactly one company. Its email carries the company's
 * demo tag (DemoNetwork::demoTag), so two companies seeded into the same area
 * never share an identity, and a Rider found by email that belongs to another
 * company stops the run instead of being reused. Existing Riders and their links
 * are never touched, and dispatch ranking is not changed.
 */
class DemoRiderFleetSeeder extends Seeder
{
    public const MAX_BARANGAYS_PER_RIDER = 3;

    /** @var array{riders: int, links: int} */
    public array $created = ['riders' => 0, 'links' => 0];

    private int $sequence = 0;

    public function run(): void
    {
        $hubs = app(LogisticsHubService::class);

        // Backups first: their two barangays then count as served when the regular Riders are cut.
        $this->eachStation(fn (string $key, LogisticsProvider $provider, LogisticsHub $station) => $this->backupRider($key, $provider, $station, $hubs));
        $this->eachStation(fn (string $key, LogisticsProvider $provider, LogisticsHub $station) => $this->staff($key, $provider, $station, $hubs));
    }

    /** @param  callable(string, LogisticsProvider, LogisticsHub): void  $callback */
    private function eachStation(callable $callback): void
    {
        foreach (DemoNetwork::areaKeys() as $key) {
            $provider = DemoNetwork::provider($key);
            if ($provider === null) {
                continue;
            }

            LogisticsHub::query()
                ->where('logistics_provider_id', $provider->id)
                ->where('hub_type', LogisticsHub::STATION)
                ->where('is_active', true)
                ->orderBy('city_municipality_code')->orderBy('id')
                ->get()
                ->unique('city_municipality_code')
                ->each(fn (LogisticsHub $station) => $callback($key, $provider, $station));
        }
    }

    /** @return Collection<int, Area> */
    private function stationAreas(LogisticsHub $station): Collection
    {
        return Area::query()->where('station_hub_id', $station->id)->where('is_active', true)
            ->whereNotNull('barangay_code')->orderBy('barangay_code')->get();
    }

    private function staff(string $key, LogisticsProvider $provider, LogisticsHub $station, LogisticsHubService $hubs): void
    {
        $prefix = 'rider'.DemoNetwork::CODES[$key].DemoNetwork::demoTag($provider, $key).DemoNetwork::municipalitySlug($station->municipality);
        $areas = $this->stationAreas($station);

        // Barangays an eligible Rider outside this family already serves stay with that Rider.
        $eligibleCouriers = Courier::query()->eligibleForAssignment()
            ->where('couriers.logistics_provider_id', $provider->id)
            ->whereHas('rider', fn ($rider) => $rider
                ->where('station_hub_id', $station->id)
                ->where('email', 'not like', $prefix.'%@'.DemoNetwork::DOMAIN))
            ->pluck('couriers.id');

        $served = DB::table('area_courier')
            ->whereIn('courier_id', $eligibleCouriers)
            ->whereIn('area_id', $areas->modelKeys())
            ->pluck('area_id')->flip();

        $groups = $areas->reject(fn (Area $area): bool => $served->has($area->id))
            ->chunk(self::MAX_BARANGAYS_PER_RIDER)->values();

        foreach ($groups as $index => $group) {
            $email = DemoNetwork::email($prefix.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT));
            $rider = $this->ownRider($provider->id, [$email]) ?? $this->rider($email, $provider->id, $station, $group->first(), $key);
            $courier = $this->ownCourier($rider, $provider->id) ?? $this->courier($rider, $provider->id, $group->first(), $station);

            $missing = $group->pluck('id')->diff($courier->areas()->pluck('areas.id'))->values()->all();
            // Never take a Rider past the limit, even when the barangays around it have shifted.
            $room = max(0, self::MAX_BARANGAYS_PER_RIDER - $courier->areas()->count());
            $missing = array_slice($missing, 0, $room);

            if ($missing !== []) {
                $hubs->linkCourierToAreas($provider->id, $courier->id, $missing);
                $this->created['links'] += count($missing);
            }
        }
    }

    private function backupRider(string $key, LogisticsProvider $provider, LogisticsHub $station, LogisticsHubService $hubs): void
    {
        $priority = $this->stationAreas($station)->take(2);
        if ($priority->isEmpty()) {
            return;
        }

        // Backups made before riders were tagged keep their address; they are reused only when this company owns them.
        $slug = DemoNetwork::CODES[$key].DemoNetwork::municipalitySlug($station->municipality);
        $tag = DemoNetwork::demoTag($provider, $key);
        $emails = array_values(array_unique([
            DemoNetwork::email('riderbackup'.$slug.'01'),
            DemoNetwork::email('riderbackup'.DemoNetwork::CODES[$key].$tag.DemoNetwork::municipalitySlug($station->municipality).'01'),
        ]));

        if ($this->ownRider($provider->id, $emails) !== null) {
            return;
        }

        $email = end($emails);
        $home = $priority->first();
        $this->created['riders']++;

        $contactBase = (int) crc32($email) % 1000000;
        $contact = DemoNetwork::phone(9, $contactBase);
        while (Rider::query()->where('contact_no', $contact)->orWhere('license_no', self::backupLicense($key, $contactBase))->orWhere('plate_number', self::backupPlate($contactBase))->exists()) {
            $contactBase = ($contactBase + 1) % 1000000;
            $contact = DemoNetwork::phone(9, $contactBase);
        }

        [$first, $last] = self::uniqueName($provider->id, $email);

        $rider = Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'email' => $email,
            'password' => DemoNetwork::passwordHash(),
            'first_name' => $first,
            'last_name' => $last,
            'birthday' => '1985-06-15',
            'contact_no' => $contact,
            'license_no' => self::backupLicense($key, $contactBase),
            'plate_number' => self::backupPlate($contactBase),
            'vehicle_type' => 'Motorcycle',
            'address_mode' => 'manual',
            'province_code' => $station->province_code,
            'province_name' => $station->province_name,
            'municipality_code' => $station->city_municipality_code,
            'municipality_name' => $station->municipality,
            'barangay_code' => $home->barangay_code,
            'barangay_name' => $home->barangay_name,
            'street' => 'Demo Rider Street',
            'status' => 'approved',
            'email_verified_at' => now(),
            'is_online' => true,
        ]);

        $courier = $this->courier($rider, $provider->id, $home, $station);

        $missing = array_values(array_diff($priority->pluck('id')->values()->all(), $courier->areas()->pluck('areas.id')->all()));
        if ($missing !== []) {
            $hubs->linkCourierToAreas($provider->id, $courier->id, $missing);
            $this->created['links'] += count($missing);
        }
    }

    /**
     * A realistic name for a demo Rider, stable for its email and different from every other Rider of
     * the company, so the Assign Rider list never shows identical entries.
     *
     * @return array{0: string, 1: string}
     */
    public static function uniqueName(int $providerId, string $email): array
    {
        $seed = (int) crc32($email) % 1200;

        for ($step = 0; $step < 1200; $step++) {
            [$first, $last] = DemoNetwork::personName($seed + $step);

            if (! Rider::query()->where('logistics_provider_id', $providerId)->where('first_name', $first)->where('last_name', $last)->exists()) {
                return [$first, $last];
            }
        }

        return DemoNetwork::personName($seed);
    }

    /**
     * The Rider this company already owns under one of the emails, trying them in order.
     * An email that exists but belongs to another company is skipped, except the last
     * one: that address is the one this company would create, so it cannot be used.
     *
     * @param  list<string>  $emails
     */
    private function ownRider(int $providerId, array $emails): ?Rider
    {
        foreach ($emails as $position => $email) {
            $rider = Rider::query()->where('email', $email)->first();

            if ($rider === null) {
                continue;
            }

            if ((int) $rider->logistics_provider_id === $providerId) {
                return $rider;
            }

            if ($position === array_key_last($emails)) {
                throw new RuntimeException("Demo Rider {$email} belongs to Logistics company {$rider->logistics_provider_id}, not {$providerId}. A Rider is never reused or reassigned across companies.");
            }
        }

        return null;
    }

    /** The Rider's fleet Courier, only when it is this company's own. */
    private function ownCourier(Rider $rider, int $providerId): ?Courier
    {
        $courier = Courier::query()->where('rider_id', $rider->id)->first();

        if ($courier !== null && (int) $courier->logistics_provider_id !== $providerId) {
            throw new RuntimeException("Courier {$courier->id} of Rider {$rider->email} belongs to another Logistics company.");
        }

        return $courier;
    }

    private function rider(string $email, int $providerId, LogisticsHub $station, Area $home, string $key): Rider
    {
        $seed = ++$this->sequence + (int) crc32($email) % 997;
        $number = $this->uniqueNumber($email, $key);
        [$first, $last] = self::uniqueName($providerId, $email);
        $this->created['riders']++;

        return Rider::factory()->create([
            'logistics_provider_id' => $providerId,
            'station_hub_id' => $station->id,
            'email' => $email,
            'password' => DemoNetwork::passwordHash(),
            'first_name' => $first,
            'last_name' => $last,
            'birthday' => (1982 + $seed % 18).'-'.str_pad((string) (1 + $seed % 12), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (1 + $seed % 27), 2, '0', STR_PAD_LEFT),
            'contact_no' => DemoNetwork::phone(2, $number),
            'license_no' => self::license($key, $number),
            'plate_number' => self::plate($number),
            'vehicle_type' => 'Motorcycle',
            'address_mode' => 'manual',
            'province_code' => $station->province_code,
            'province_name' => $station->province_name,
            'municipality_code' => $station->city_municipality_code,
            'municipality_name' => $station->municipality,
            'barangay_code' => $home->barangay_code,
            'barangay_name' => $home->barangay_name,
            'street' => 'Demo Rider Street',
            'status' => 'approved',
            'email_verified_at' => now(),
            'is_online' => true,
        ]);
    }

    /** A number unused by any Rider contact, licence or plate, stable for an email unless it collides. */
    private function uniqueNumber(string $email, string $key): int
    {
        $number = (int) crc32($email) % 1000000;

        while (Rider::query()->where('plate_number', self::plate($number))->orWhere('license_no', self::license($key, $number))->orWhere('contact_no', DemoNetwork::phone(2, $number))->exists()) {
            $number = ($number + 1) % 1000000;
        }

        return $number;
    }

    private static function plate(int $number): string
    {
        return sprintf('DEM %06d', $number);
    }

    private static function license(string $key, int $number): string
    {
        return sprintf('DEMO-%s-%06d', strtoupper(DemoNetwork::CODES[$key]), $number);
    }

    private static function backupPlate(int $number): string
    {
        return 'DEM-B'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private static function backupLicense(string $key, int $number): string
    {
        return 'DEMO-BACK-'.strtoupper(DemoNetwork::CODES[$key]).'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function courier(Rider $rider, int $providerId, Area $home, LogisticsHub $station): Courier
    {
        return Courier::query()->create([
            'logistics_provider_id' => $providerId,
            'rider_id' => $rider->id,
            'area_id' => $home->id,
            'name' => $rider->fullName(),
            'phone' => $rider->contact_no,
            'service_area' => [$station->municipality],
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 20000,
            'is_available' => true,
        ]);
    }
}
