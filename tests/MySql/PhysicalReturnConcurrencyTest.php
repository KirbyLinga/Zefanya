<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\OrderItem;
use App\Services\Logistics\ParcelReturnService;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class PhysicalReturnConcurrencyTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;

    private static bool $migrated = false;

    private static $runnerLock;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
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
            $this->markTestSkipped('Existing owned disposable MySQL schema is required.');
        }
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function fixture(): array
    {
        $a = $this->operationalHandoffProvider('Laguna A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-RETURN-RACE', OrderStatus::DeliveryFailed);
        $parcel->forceFill(['delivery_attempts' => 2, 'origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $a['provider']->id, 'custody_hub_id' => $a['station']->id,
            'pickup_address_snapshot' => ['full' => 'Original Seller pickup', 'barangay_code' => '0434050001', 'province_code' => '043400000']])->save();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3]);
        $variant = $product->variants()->firstOrFail();
        OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);

        return compact('a', 'seller', 'parcel', 'variant');
    }

    public function test_concurrent_return_requests_create_one_active_return_and_request_event(): void
    {
        $f = $this->fixture();
        $task = ['action' => 'return_request', 'parcel' => $f['parcel']->id, 'provider' => $f['a']['provider']->id];
        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);
        foreach ($results as $result) {
            $this->assertSame('requested', $result['status']);
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame($results['a']['return_id'], $results['b']['return_id']);
        $this->assertSame(1, ParcelReturn::query()->count());
        $this->assertSame(1, ParcelReturn::query()->sole()->events()->count());
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }

    public function test_concurrent_seller_receipts_finalize_and_restore_stock_once(): void
    {
        $f = $this->fixture();
        $service = app(ParcelReturnService::class);
        $record = $service->request($f['parcel'], $f['a']['provider'], 'Physical return.');
        $scan = $service->scan($record, $f['a']['provider'], $f['parcel']->tracking_number, 'origin');
        $service->confirmOrigin($record, $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $scan = $service->scan($record, $f['a']['provider'], $f['parcel']->tracking_number, 'handover');
        $code = $service->prepareHandover($record, $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $task = ['action' => 'return_receipt', 'return' => $record->id, 'seller' => $f['seller']->id, 'handover_code' => $code];
        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);
        $this->assertSame('completed', $results['a']['status']);
        $this->assertSame('rejected', $results['b']['status']);
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(ParcelReturnStatus::Completed, $record->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $f['parcel']->fresh()->status);
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertSame(1, $record->events()->where('event_key', 'completed')->count());
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }
}
