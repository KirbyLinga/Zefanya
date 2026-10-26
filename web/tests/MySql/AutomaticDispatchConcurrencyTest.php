<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Models\DeliveryAssignment;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class AutomaticDispatchConcurrencyTest extends TestCase
{
    use CreatesAssignableFleet;

    private static bool $migrated = false;

    private static $runnerLock;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Lock the existing runner file read-only; never write protected .codex files.
        self::$runnerLock = fopen(dirname(__DIR__, 2).'/.codex/t05-runner.lock', 'r');
        if (! self::$runnerLock || ! flock(self::$runnerLock, LOCK_EX | LOCK_NB)) {
            self::markTestSkipped('The existing isolated MySQL runner lock is unavailable.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$runnerLock)) {
            flock(self::$runnerLock, LOCK_UN);
            fclose(self::$runnerLock);
        }
        parent::tearDownAfterClass();
    }

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(dirname(__DIR__, 2).'/.codex/t05-owned-schema.json')) {
            $this->markTestSkipped('An existing owned disposable MySQL schema is required.');
        }
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function fixture(): array
    {
        $provider = LogisticsProvider::factory()->create();
        $courier = $this->assignableCourier($provider, [], 'San Fernando', '030540100');
        $seller = Seller::factory()->create(['barangay_code' => '0305401001']);
        $make = function (string $role) use ($provider, $courier, $seller): SellerOrder {
            return SellerOrder::factory()->create([
                'seller_id' => $seller->id,
                'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001']])->id,
                'status' => $role === 'pickup' ? OrderStatus::ReadyForPickup->value : OrderStatus::Sorted->value,
                'carrier_provider_id' => $provider->id,
                'origin_station_hub_id' => $courier->rider->station_hub_id,
            ]);
        };

        return [$courier, $make];
    }

    public static function concurrentLegs(): array
    {
        return [['pickup', 'pickup'], ['delivery', 'delivery'], ['pickup', 'delivery']];
    }

    #[DataProvider('concurrentLegs')]
    public function test_competing_parcels_cannot_exceed_one_rider_capacity(string $firstRole, string $secondRole): void
    {
        [$courier, $make] = $this->fixture();
        $a = $make($firstRole);
        $b = $make($secondRole);
        $results = Race::run(
            ['action' => 'dispatch', 'parcel' => $a->id, 'role' => $firstRole, 'hold' => 'provider'],
            ['action' => 'dispatch', 'parcel' => $b->id, 'role' => $secondRole, 'watch' => 'provider'],
        );
        $this->assertSame('assigned', $results['a']['status']);
        $this->assertSame('unavailable', $results['b']['status']);
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
            $this->assertArrayNotHasKey('exception', $result);
        }
        $this->assertSame(1, $courier->fresh()->activeWorkload());
        $this->assertSame(1, DeliveryAssignment::query()->count());
        $this->assertTrue($b->fresh()->needs_reassignment);
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }

    public function test_concurrent_duplicate_requests_reserve_the_same_parcel_only_once(): void
    {
        [$courier, $make] = $this->fixture();
        $parcel = $make('pickup');
        $results = Race::run(
            ['action' => 'dispatch', 'parcel' => $parcel->id, 'role' => 'pickup', 'hold' => 'provider'],
            ['action' => 'dispatch', 'parcel' => $parcel->id, 'role' => 'pickup', 'watch' => 'provider'],
        );
        foreach ($results as $result) {
            $this->assertSame('assigned', $result['status']);
            $this->assertSame($courier->id, (int) $result['courier_id']);
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $parcel->deliveryAssignments()->count());
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }
}
