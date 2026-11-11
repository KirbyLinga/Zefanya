<?php

namespace Tests\MySql;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransportConnection;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\RoutePlannerService;
use App\Services\Logistics\TransferCourierService;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LogisticsConcurrencyTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    private function fixture(): array
    {
        $a = $this->operationalHandoffProvider('Concurrent Logistics', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'First');
        $destination = $a['station']->replicate();
        $destination->fill(['name' => 'Second Station', 'barangay_code' => '0305401002'])->save();
        $area = $a['area']->replicate();
        $area->fill(['name' => 'Second Coverage', 'station_hub_id' => $destination->id, 'barangay_code' => '0305401002'])->save();
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'First');
        $buyer = $this->buyerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401002', 'Second');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-MYSQL-CUSTODY');
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $a['provider']->id, 'custody_hub_id' => $a['station']->id])->save();
        StatusHistory::record($parcel, OrderStatus::PickedUp, OrderStatus::AtSortingCenter, actor: OrderActor::Logistics, actorId: $a['provider']->id);
        $connection = TransportConnection::query()->create(['origin_hub_id' => $a['station']->id, 'destination_hub_id' => $destination->id,
            'transport_mode' => 'ROAD', 'operating_provider_id' => $a['provider']->id]);
        $route = app(RoutePlannerService::class)->planRoute($seller, $a['provider'], '0305401002', '030540000');
        $route->forceFill(['seller_order_id' => $parcel->id, 'route_planned_at' => now()])->save();

        return compact('a', 'destination', 'parcel', 'connection', 'route');
    }

    public function test_concurrent_verified_receipts_serialize_custody_and_complete_once(): void
    {
        $f = $this->fixture();
        $service = app(ManifestService::class);
        $manifest = $service->create($f['a']['provider'], $f['a']['station']->id, $f['destination']->id);
        $item = $service->addParcel($manifest, $f['parcel']);
        $courier = (new TransferCourier)->forceFill([
            'logistics_provider_id' => $f['a']['provider']->id,
            'name' => 'Custody Courier',
            'contact_no' => '09170000001',
            'vehicle_type' => 'Van',
            'plate_number' => 'VAN 100',
            'status' => TransferCourier::ACTIVE,
        ]);
        $courier->save();
        app(TransferCourierService::class)->assignToManifest($manifest, $f['a']['provider'], $courier->id, null, null);
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['a']['provider'], [$item->id => $scan['verification_token']]);
        $task = ['action' => 'phase6_receive', 'provider' => $f['a']['provider']->id, 'manifest' => $manifest->id, 'item' => $item->id];

        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);

        $this->assertSame(1, collect($results)->where('success', true)->count());
        $this->assertNotNull($item->fresh()->received_at);
        $this->assertNotNull($f['route']->fresh()->route_completed_at);
        $this->assertSame($f['destination']->id, $f['parcel']->fresh()->custody_hub_id);
        $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame(1, ParcelRoute::query()->count());
    }

    public function test_concurrent_rerouting_records_one_decision_and_one_route_revision(): void
    {
        $f = $this->fixture();
        foreach ([[$f['a']['station'], $f['a']['province']], [$f['a']['province'], $f['destination']]] as [$origin, $destination]) {
            TransportConnection::query()->create(['origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id,
                'transport_mode' => 'ROAD', 'operating_provider_id' => $f['a']['provider']->id]);
        }
        $f['connection']->update(['is_active' => false]);
        $incident = app(ParcelIncidentService::class)->reportOperational($f['parcel'], $f['a']['provider'],
            ['type' => 'inactive_connection', 'reason' => 'Original connection unavailable.', 'description' => 'Alternative connections configured.']);
        $task = ['action' => 'phase6_reroute', 'provider' => $f['a']['provider']->id, 'incident' => $incident->id];

        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);

        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success']);
        $this->assertSame(1, ParcelRouteRereoute::query()->count());
        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertSame(1, $incident->events()->where('event', 'resolved')->count());
        $this->assertSame([$f['a']['province']->id, $f['destination']->id], array_column($f['route']->fresh()->legs, 'hub_id'));
        $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
        $this->assertNull($f['route']->fresh()->route_completed_at);
    }
}
