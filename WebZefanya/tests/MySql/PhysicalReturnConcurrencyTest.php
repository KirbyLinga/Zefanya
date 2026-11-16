<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\OrderItem;
use App\Models\Shared\StatusHistory;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\StationInventoryService;
use App\Services\Logistics\TransferCourierService;
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

    public function test_return_pickup_assignment_and_collection_each_serialize_replays(): void
    {
        $f = $this->fixture();
        $f['parcel']->forceFill(['status' => OrderStatus::Delivered])->save();
        StatusHistory::query()->create(['seller_order_id' => $f['parcel']->id,
            'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $provider = $f['a']['provider'];
        $rider = Rider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'is_online' => true,
            'logistics_provider_id' => $provider->id, 'station_hub_id' => $f['a']['station']->id]);
        $courier = Courier::factory()->create(['rider_id' => $rider->id, 'logistics_provider_id' => $provider->id]);
        $service = app(ParcelReturnService::class);
        app(BuyerShipmentConfirmation::class)->confirm($f['parcel']->order, $f['parcel']->order->buyer, $f['parcel']->id);
        $return = $service->requestByBuyer($f['parcel'], $f['parcel']->order->buyer, 'Wrong item');
        $service->approveBySeller($return, $f['seller']);
        $base = ['action' => 'return_pickup', 'return' => $return->id];
        $assign = $base + ['provider' => $provider->id, 'courier' => $courier->id];
        $assignment = Race::run($assign + ['hold' => 'parcel'], $assign + ['watch' => 'parcel']);
        foreach ($assignment as $result) {
            $this->assertTrue($result['success']);
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $return->events()->where('event_key', 'like', 'collection_assigned%')->count());
        $this->assertNull($return->fresh()->collected_at);

        $replacement = Rider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'is_online' => true,
            'logistics_provider_id' => $provider->id, 'station_hub_id' => $f['a']['station']->id]);
        $replacementCourier = Courier::factory()->create(['rider_id' => $replacement->id, 'logistics_provider_id' => $provider->id]);
        $reassign = $base + ['provider' => $provider->id, 'courier' => $replacementCourier->id];
        $replacementRace = Race::run($assign + ['hold' => 'parcel'], $reassign + ['watch' => 'parcel']);
        $this->assertTrue($replacementRace['a']['success']);
        $this->assertTrue($replacementRace['b']['success']);
        $this->assertSame($replacementCourier->id, (int) $return->fresh()->collection_courier_id);
        $this->assertSame(2, $return->events()->where('event_key', 'like', 'collection_assigned%')->count());

        $collect = $base + ['rider' => $replacement->id];
        $station = $base + ['provider' => $provider->id, 'station' => $f['a']['station']->id];
        $collection = Race::run($collect + ['hold' => 'parcel'], $station + ['watch' => 'parcel']);
        $this->assertTrue($collection['a']['success']);
        $this->assertTrue($collection['b']['success']);
        $this->assertSame(1, $return->events()->where('event_key', 'collected_from_buyer')->count());
        $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
        $receipts = Race::run($station + ['hold' => 'parcel'], $station + ['watch' => 'parcel']);
        foreach ($receipts as $result) {
            $this->assertTrue($result['success']);
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $return->events()->where('event_key', 'station_received')->count());
        $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
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

    private function collectedBuyerReturn(bool $cross = false): array
    {
        $f = $this->fixture();
        $holder = $cross
            ? $this->operationalHandoffProvider('Quezon B', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I')
            : $f['a'];
        if ($cross) {
            $f['parcel']->order->forceFill(['shipping_address' => ['barangay_code' => '0456240001', 'province_code' => '045600000']])->save();
        }
        $f['parcel']->forceFill(['status' => OrderStatus::Delivered, 'custody_provider_id' => $holder['provider']->id,
            'custody_hub_id' => $holder['station']->id])->save();
        StatusHistory::query()->create(['seller_order_id' => $f['parcel']->id,
            'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $rider = Rider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'is_online' => true,
            'logistics_provider_id' => $holder['provider']->id, 'station_hub_id' => $holder['station']->id]);
        $courier = Courier::factory()->create(['rider_id' => $rider->id, 'logistics_provider_id' => $holder['provider']->id]);
        $service = app(ParcelReturnService::class);
        app(BuyerShipmentConfirmation::class)->confirm($f['parcel']->order, $f['parcel']->order->buyer, $f['parcel']->id);
        $record = $service->requestByBuyer($f['parcel']->fresh(), $f['parcel']->order->buyer, 'Wrong item');
        $service->approveBySeller($record, $f['seller']);
        $service->assignBuyerCollection($record, $holder['provider'], $courier->id);
        $service->confirmBuyerCollection($record, $rider, $f['parcel']->tracking_number);
        $f['holder'] = $holder;
        $f['record'] = $record;
        $f['receipt'] = ['action' => 'return_pickup', 'return' => $record->id, 'provider' => $holder['provider']->id,
            'station' => $holder['station']->id];

        return $f;
    }

    private function inventoryCounts(array $f): array
    {
        $inventory = app(StationInventoryService::class);

        return $inventory->counts($inventory->rows($f['holder']['provider']->id, collect([$f['holder']['station']->id])));
    }

    public function test_station_receipt_serializes_against_buyer_cancellation(): void
    {
        $f = $this->collectedBuyerReturn();
        $cancel = ['action' => 'return_cancel', 'return' => $f['record']->id, 'buyer' => $f['parcel']->order->buyer_id];
        $results = Race::run($f['receipt'] + ['hold' => 'parcel'], $cancel + ['watch' => 'parcel']);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $this->inventoryCounts($f)['inside']);
        $this->assertSame(1, $f['record']->events()->where('event_key', 'station_received')->count());
    }

    public function test_loss_resolution_serializes_against_station_receipt(): void
    {
        $f = $this->collectedBuyerReturn();
        $admin = Admin::factory()->create();
        $incident = app(ParcelReturnService::class)->reportTransitProblem($f['record'], $f['holder']['provider'],
            ['type' => 'missing', 'reason' => 'The collected parcel cannot be found.']);
        $loss = ['action' => 'return_loss', 'return' => $f['record']->id, 'incident' => $incident->id, 'admin' => $admin->id];
        $results = Race::run($loss + ['hold' => 'parcel'], $f['receipt'] + ['watch' => 'parcel']);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(ParcelReturnStatus::Lost, $f['record']->fresh()->status);
        $this->assertSame(0, $this->inventoryCounts($f)['total']);
        $this->assertSame(0, $f['record']->events()->where('event_key', 'station_received')->count());
    }

    public function test_reverse_handoff_waits_for_committed_receipt_and_dispatch_serializes_replays(): void
    {
        $f = $this->collectedBuyerReturn(true);
        $admin = Admin::factory()->create();
        $this->activeHandoffLink($f['holder']['provider'], $f['a']['provider'], $admin);
        $reverse = ['action' => 'return_reverse', 'return' => $f['record']->id, 'provider' => $f['holder']['provider']->id];
        $results = Race::run($f['receipt'] + ['hold' => 'parcel'], $reverse, rejectionWithoutLock: true);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $handoff = app(ParcelReturnService::class)->reverseHandoff($f['record']->fresh(), $f['holder']['provider']);
        $handoff = app(ParcelHandoffService::class)->accept($handoff, $f['a']['provider']);
        $transport = app(TransferCourierService::class);
        $courier = $transport->save($f['holder']['provider'], ['name' => 'Return transport Courier',
            'contact_no' => '09171234567', 'vehicle_type' => 'Motorcycle', 'plate_number' => 'INV 1234']);
        $handoff = $transport->assignToHandoff($handoff, $f['holder']['provider'], $courier->id, 'Motorcycle', 'INV 1234');
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $this->inventoryCounts($f)['inside'], 'An undispatched handoff remains at its station.');
        $this->assertSame(1, $f['record']->events()->where('event_key', 'station_received')->count());
        $this->assertSame(1, $f['parcel']->parcelHandoffs()->where('direction', 'return')->count());
        $dispatch = ['action' => 'return_reverse_dispatch', 'return' => $f['record']->id,
            'provider' => $f['holder']['provider']->id, 'handoff' => $handoff->id];
        $results = Race::run($f['receipt'] + ['hold' => 'parcel'], $dispatch + ['watch' => 'parcel']);
        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success'], json_encode($results['b']));
        $this->assertSame(0, $this->inventoryCounts($f)['inside']);
        $inventory = app(StationInventoryService::class);
        $destination = $inventory->counts($inventory->rows($f['a']['provider']->id, collect([$f['a']['station']->id])));
        $this->assertSame(0, $destination['inside']);
        $this->assertSame(1, $destination['inbound']);
        $this->assertSame(1, $f['record']->events()->where('event_key', 'station_received')->count());
        $handoffs = app(ParcelHandoffService::class);
        $scan = $handoffs->verifyScan($handoff->fresh(), $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $handoffs->receive($handoff->fresh(), $f['a']['provider'], $scan['verification_token']);
        $destination = $inventory->counts($inventory->rows($f['a']['provider']->id, collect([$f['a']['station']->id])));
        $this->assertSame(1, $destination['inside']);
        $this->assertSame(0, $destination['inbound']);
        $this->assertSame(0, $this->inventoryCounts($f)['total']);
    }

    public function test_inventory_read_does_not_see_uncommitted_station_receipt(): void
    {
        $f = $this->collectedBuyerReturn();
        $read = ['action' => 'station_inventory', 'provider' => $f['holder']['provider']->id, 'station' => $f['holder']['station']->id];
        $results = Race::run($f['receipt'] + ['hold' => 'return_event'], $read, rejectionWithoutLock: true);
        $this->assertTrue($results['a']['success']);
        $this->assertSame(0, $results['b']['counts']['inside']);
        $this->assertSame(1, $this->inventoryCounts($f)['inside']);
        $this->assertSame(1, $f['record']->events()->where('event_key', 'station_received')->count());
    }
}
