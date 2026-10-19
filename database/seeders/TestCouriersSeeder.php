<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Database\Seeder;

/**
 * Seeds assignable test couriers onto a developer's own logistics account so
 * the logistics pipeline (claim → sort → assign delivery) always has an
 * eligible rider in the fleet to hand a parcel to.
 *
 * Idempotent: riders are keyed by phone, so re-running keeps exactly the
 * planned set. An idle rider is (re)made available; a rider still holding a
 * parcel stays busy, so the seeder is safe to run at any time.
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

        foreach (self::plannedRiders() as $rider) {
            $courier = Courier::firstOrNew(['phone' => $rider['phone']]);

            $courier->fill([
                'logistics_provider_id' => $provider->getKey(),
                'name' => $rider['name'],
                'service_area' => self::SERVICE_AREA,
                'vehicle_type' => $rider['vehicle_type'],
                'max_weight_grams' => $rider['max_weight_grams'],
            ]);

            // Idle riders are (re)made available; a rider mid-run is left busy.
            $courier->is_available = ! $this->isHoldingParcel($courier);

            $courier->save();
        }

        $this->command?->info('Test couriers seeded for '.self::PROVIDER_EMAIL.'.');
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
