<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\SellerOrder;
use Illuminate\Database\Seeder;

/**
 * Seeds assignable test riders onto a developer's own logistics account so
 * the logistics pipeline (claim → sort → assign delivery) always has an
 * eligible rider in the fleet to hand a parcel to.
 *
 * Each rider owns one Courier fleet record (the assignment entity), an
 * approved active Station Hub, and municipality coverage for every seeded
 * service area.
 *
 * Idempotent: riders/couriers are keyed by phone, so re-running keeps exactly
 * the planned set. An idle rider is (re)made available; a rider still holding
 * a parcel stays busy, so the seeder is safe to run at any time.
 *
 * Dev-only. Run with:
 *   php artisan db:seed --class=TestCouriersSeeder
 */
class TestCouriersSeeder extends Seeder
{
    /** Logistics account that owns the seeded riders. */
    public const PROVIDER_EMAIL = 'penguinskipper9@gmail.com';

    /**
     * Municipalities every seeded rider covers — kept to the destinations in
     * the current dev data so a parcel bound for either finds a candidate in
     * CourierAssignmentService::candidateFor().
     *
     * @var list<string>
     */
    public const SERVICE_AREA = ['Plaridel', 'Quezon City'];

    public const STATION_NAME = 'Test Rider Station Hub';

    public const PROVINCE_NAME = 'Bulacan';

    /**
     * Statuses during which a rider is still holding a parcel and must stay
     * unavailable. Mirrors the release points in IncomingParcelsController
     * (released at AtSortingCenter) and DeliveryAssignmentsController
     * (released at Delivered / DeliveryFailed).
     *
     * @var list<OrderStatus>
     */
    private const IN_FLIGHT_STATUSES = [
        OrderStatus::PickedUp,
        OrderStatus::AssignedToRider,
        OrderStatus::OutForDelivery,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function plannedRiders(): array
    {
        return [
            [
                'name' => 'Test Rider 1',
                'phone' => '09170000201',
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'name' => 'Test Rider 2',
                'phone' => '09170000202',
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'name' => 'Test Rider 3',
                'phone' => '09170000203',
                'vehicle_type' => 'van',
                'max_weight_grams' => 20000,
            ],
        ];
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('TestCouriersSeeder refuses to run in production.');

            return;
        }

        $provider = LogisticsProvider::where('email', self::PROVIDER_EMAIL)->first();

        if ($provider === null) {
            $this->command?->error('No logistics provider found for '.self::PROVIDER_EMAIL.' — skipping.');

            return;
        }

        $station = $this->stationFor($provider);
        $areas = collect(self::SERVICE_AREA)
            ->mapWithKeys(fn (string $municipality): array => [
                $municipality => $this->coverageFor($provider, $station, $municipality),
            ]);

        foreach (self::plannedRiders() as $index => $planned) {
            $courier = Courier::firstOrNew(['phone' => $planned['phone']]);
            $rider = $courier->rider_id !== null
                ? Rider::find($courier->rider_id)
                : null;
            $rider ??= Rider::firstOrNew(['contact_no' => $planned['phone']]);

            $nameParts = explode(' ', $planned['name'], 2);

            $rider->fill([
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? 'Rider',
                'birthday' => '1995-03-22',
                'email' => 'test.rider.'.($index + 1).'@example.com',
                'contact_no' => $planned['phone'],
                'license_no' => 'TST-00-00000'.($index + 1),
                'license_expiry' => '2030-03-22',
                'vehicle_type' => ucfirst($planned['vehicle_type']),
                'plate_number' => 'TSTR'.($index + 1),
                'drivers_license_path' => 'rider-licenses/test.jpg',
                'or_cr_path' => 'rider-or-crs/test.pdf',
            ]);
            $rider->forceFill([
                'logistics_provider_id' => $provider->getKey(),
                'station_hub_id' => $station->id,
                'status' => Rider::STATUS_APPROVED,
                'email_verified_at' => now(),
                'status_changed_at' => now(),
            ])->save();

            $courier->fill([
                'logistics_provider_id' => $provider->getKey(),
                'rider_id' => $rider->id,
                'area_id' => $areas->first()?->id,
                'name' => $planned['name'],
                'service_area' => self::SERVICE_AREA,
                'vehicle_type' => $planned['vehicle_type'],
                'max_weight_grams' => $planned['max_weight_grams'],
            ]);

            // Idle riders are (re)made available; a rider mid-run is left busy.
            $courier->is_available = ! $this->isHoldingParcel($courier);

            $courier->save();
        }

        $this->command?->info('Test couriers seeded for '.self::PROVIDER_EMAIL.'.');
    }

    private function stationFor(LogisticsProvider $provider): LogisticsHub
    {
        $provinceCode = (string) ($provider->province_code ?: '030140000');
        $provinceName = (string) ($provider->province_name ?: self::PROVINCE_NAME);

        $province = LogisticsHub::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'hub_type' => LogisticsHub::PROVINCE,
            'name' => $provinceName.' Province Hub',
        ], [
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
            'active_province_slot' => 1,
        ]);

        return LogisticsHub::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'hub_type' => LogisticsHub::STATION,
            'name' => self::STATION_NAME,
        ], [
            'parent_hub_id' => $province->id,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'municipality' => 'Quezon City',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);
    }

    private function coverageFor(LogisticsProvider $provider, LogisticsHub $station, string $municipality): Area
    {
        return Area::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'municipality' => $municipality,
        ], [
            'station_hub_id' => $station->id,
            'name' => $municipality,
            'is_active' => true,
        ]);
    }

    /**
     * True when the rider still has a parcel in the pickup or delivery leg.
     */
    private function isHoldingParcel(Courier $courier): bool
    {
        if (! $courier->exists) {
            return false;
        }

        return SellerOrder::query()
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->where(function ($query) use ($courier): void {
                $query->where('courier_id', $courier->getKey())
                    ->orWhere('delivery_courier_id', $courier->getKey());
            })
            ->exists();
    }
}
