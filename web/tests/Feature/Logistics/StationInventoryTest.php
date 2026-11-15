<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\Area;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\StationInventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class StationInventoryTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs, LazilyRefreshDatabase;

    private function fixture(): array
    {
        $a = $this->operationalHandoffProvider('Inventory provider', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $station = $a['station']->replicate();
        $station->name = 'Buyer return station';
        $station->barangay_code = '0434050002';
        $station->save();
        Area::query()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id,
            'name' => 'Buyer coverage', 'municipality' => 'Calamba', 'barangay_code' => '0434050002',
            'city_municipality_code' => '043405000', 'coverage_validated_at' => now(), 'is_active' => true]);
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050002', 'Second');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-INVENTORY', OrderStatus::Delivered);
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $a['provider']->id,
            'custody_hub_id' => $station->id, 'pickup_address_snapshot' => ['full' => 'Saved Seller address',
                'barangay_code' => '0434050001', 'province_code' => '043400000']])->save();
        StatusHistory::query()->create(['seller_order_id' => $parcel->id,
            'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $rider = Rider::factory()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id,
            'status' => 'approved', 'email_verified_at' => now(), 'is_online' => true]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $a['provider']->id, 'rider_id' => $rider->id]);
        $service = app(ParcelReturnService::class);
        $return = $service->requestByBuyer($parcel, $buyer, 'Wrong item');
        $service->approveBySeller($return, $seller);
        $service->assignBuyerCollection($return, $a['provider'], $courier->id);

        return compact('a', 'station', 'seller', 'buyer', 'parcel', 'rider', 'courier', 'return');
    }

    private function counts(array $f, ?int $station = null): array
    {
        $service = app(StationInventoryService::class);

        return $service->counts($service->rows($f['a']['provider']->id, collect([$station ?? $f['station']->id])));
    }

    private function collectAndReceive(array $f): void
    {
        $service = app(ParcelReturnService::class);
        $service->confirmBuyerCollection($f['return'], $f['rider'], $f['parcel']->tracking_number);
        $service->receiveFromBuyerCollection($f['return'], $f['a']['provider'], $f['station']->id, $f['parcel']->tracking_number);
    }

    public function test_buyer_return_inventory_tracks_receipt_dispatch_arrival_and_seller_receipt(): void
    {
        $f = $this->fixture();
        $service = app(ParcelReturnService::class);
        $empty = ['inside' => 0, 'to_sort' => 0, 'exceptions' => 0, 'inbound' => 0, 'total' => 0];
        $held = ['inside' => 1, 'to_sort' => 0, 'exceptions' => 0, 'inbound' => 0, 'total' => 1];
        $this->assertSame($empty, $this->counts($f));
        $service->confirmBuyerCollection($f['return'], $f['rider'], $f['parcel']->tracking_number);
        $this->assertSame($empty, $this->counts($f));
        $service->receiveFromBuyerCollection($f['return'], $f['a']['provider'], $f['station']->id, $f['parcel']->tracking_number);
        $service->receiveFromBuyerCollection($f['return'], $f['a']['provider'], $f['station']->id, $f['parcel']->tracking_number);
        $this->assertSame($held, $this->counts($f));
        $this->assertSame(1, $f['return']->events()->where('event_key', 'station_received')->count());
        $this->actingAs($f['a']['provider'], 'logistics')->get(route('logistics.hubs.index'))->assertOk()
            ->assertViewHas('stationInventory', fn ($counts) => $counts[$f['station']->id] === $held)
            ->assertSee(route('logistics.delivery-monitoring.index', ['station' => $f['station']->id]), false);
        $this->get(route('logistics.delivery-monitoring.index', ['station' => $f['station']->id]))->assertOk()
            ->assertSee('Buyer Return')->assertSee('Received by Logistics station')
            ->assertDontSee($f['buyer']->email)->assertDontSee('Saved Seller address')
            ->assertViewHas('inventoryRows', fn ($rows) => $rows->total() === 1);

        $manifest = $service->reverseManifest($f['return'], $f['a']['provider'], $f['a']['station']->id);
        $item = $manifest->items()->sole();
        $this->assertSame($held, $this->counts($f)); // A draft does not leave the station.
        $manifests = app(ManifestService::class);
        $scan = $manifests->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $manifests->dispatch($manifest, $f['a']['provider'], [$item->id => $scan['verification_token']]);
        $this->assertSame($empty, $this->counts($f));
        $this->assertSame(['inside' => 0, 'to_sort' => 0, 'exceptions' => 0, 'inbound' => 1, 'total' => 1], $this->counts($f, $f['a']['station']->id));
        $scan = $manifests->verifyScan($manifest->fresh(), $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $manifests->receive($manifest->fresh(), $f['a']['provider'], $item, $scan['verification_token']);
        $this->assertSame($empty, $this->counts($f));
        $this->assertSame($held, $this->counts($f, $f['a']['station']->id));
        $scan = $service->scan($f['return'], $f['a']['provider'], $f['parcel']->tracking_number, 'handover');
        $code = $service->prepareHandover($f['return'], $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $token = $service->verifySeller($f['return'], $f['seller'], $f['parcel']->tracking_number, $code, 'pending_review');
        $service->receiveBySeller($f['return'], $f['seller'], 'pending_review', $token);
        $this->assertSame($empty, $this->counts($f, $f['a']['station']->id));
        $this->assertSame(ParcelReturnStatus::Completed, $f['return']->fresh()->status);
        $this->assertTrue($f['return']->events()->where('event_key', 'station_received')->exists());
    }

    public function test_legacy_requested_custody_is_not_receipt_but_verified_later_stages_remain_visible(): void
    {
        $f = $this->fixture();
        $service = app(ParcelReturnService::class);
        $service->confirmBuyerCollection($f['return'], $f['rider'], $f['parcel']->tracking_number);
        $f['parcel']->forceFill(['custody_hub_id' => $f['station']->id])->save();
        $this->assertSame(0, $this->counts($f)['inside']);
        $this->assertFalse($f['return']->hasStationReceipt());
        // Historical later-stage custody remains valid without fabricating a new receipt.
        $f['return']->forceFill(['status' => ParcelReturnStatus::AtOrigin])->save();
        $f['parcel']->forceFill(['custody_hub_id' => $f['a']['station']->id])->save();
        $this->assertSame(1, $this->counts($f, $f['a']['station']->id)['inside']);
        $this->assertFalse($f['return']->hasStationReceipt());
        foreach ([ParcelReturnStatus::Completed, ParcelReturnStatus::Lost, ParcelReturnStatus::Damaged, ParcelReturnStatus::Cancelled] as $status) {
            $f['return']->forceFill(['status' => $status])->save();
            $this->assertSame(0, $this->counts($f, $f['a']['station']->id)['total']);
        }
    }

    public function test_inventory_is_provider_and_station_scoped_and_read_only(): void
    {
        $f = $this->fixture();
        $this->collectAndReceive($f);
        $foreign = $this->operationalHandoffProvider('Foreign inventory', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        $this->assertCount(0, app(StationInventoryService::class)->rows($foreign['provider']->id, collect([$f['station']->id])));
        $this->actingAs($foreign['provider'], 'logistics')->get(route('logistics.delivery-monitoring.index', ['station' => $f['station']->id]))->assertNotFound();
        $this->get(route('logistics.delivery-monitoring.index', ['station' => $foreign['station']->id]))->assertOk()->assertDontSee('TRK-INVENTORY');
        $this->actingAs($f['a']['provider'], 'logistics');
        $before = $this->databaseSnapshot();
        $this->get(route('logistics.hubs.index'))->assertOk();
        $this->get(route('logistics.delivery-monitoring.index', ['station' => $f['station']->id]))->assertOk();
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_outbound_sorting_and_return_exceptions_keep_existing_classifications(): void
    {
        $f = $this->fixture();
        $this->collectAndReceive($f);
        $outbound = $this->handoffParcel($f['seller'], $f['buyer'], $f['a']['provider'], 'TRK-OUTBOUND', OrderStatus::AtSortingCenter);
        $outbound->forceFill(['custody_provider_id' => $f['a']['provider']->id, 'custody_hub_id' => $f['station']->id])->save();
        $this->assertSame(['inside' => 2, 'to_sort' => 1, 'exceptions' => 0, 'inbound' => 0, 'total' => 2], $this->counts($f));
        app(ParcelReturnService::class)->reportTransitProblem($f['return'], $f['a']['provider'],
            ['type' => 'missing', 'reason' => 'Not found in the receiving bin']);
        $this->assertSame(['inside' => 2, 'to_sort' => 1, 'exceptions' => 1, 'inbound' => 0, 'total' => 2], $this->counts($f));
        $this->assertSame(0, $this->counts($f, $f['a']['station']->id)['exceptions']);
        $outbound->forceFill(['status' => OrderStatus::DeliveryFailed])->save();
        $this->assertSame(1, $this->counts($f)['inside'], 'Failed delivery stays with its Rider until a station receipt.');
    }

    public function test_duplicate_reverse_movements_do_not_duplicate_expected_inventory_and_recovery_is_not_inside(): void
    {
        $f = $this->fixture();
        $this->collectAndReceive($f);
        $service = app(ManifestService::class);
        $manifest = app(ParcelReturnService::class)->reverseManifest($f['return'], $f['a']['provider'], $f['a']['station']->id);
        $item = $manifest->items()->sole();
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['a']['provider'], [$item->id => $scan['verification_token']]);
        // Historical movements referencing the same parcel must not add inventory.
        $duplicate = $manifest->fresh()->replicate();
        $duplicate->save();
        $duplicateItem = $item->replicate(['active_seller_order_id']);
        $duplicateItem->received_at = now();
        $duplicateItem->released_at = now();
        $duplicateItem->manifest_id = $duplicate->id;
        $duplicateItem->save();
        $this->assertSame(1, $this->counts($f, $f['a']['station']->id)['inbound']);
        $this->actingAs($f['a']['provider'], 'logistics')->get(route('logistics.delivery-monitoring.index', ['station' => $f['a']['station']->id]))
            ->assertOk()->assertSee('In transport; awaiting station receipt')
            ->assertViewHas('inventoryRows', fn ($rows) => $rows->total() === 1);
        $manifest->forceFill(['status' => ManifestStatus::Cancelled])->save();
        $duplicate->forceFill(['status' => ManifestStatus::Cancelled])->save();
        $this->assertSame(0, $this->counts($f)['inside'], 'Cancelled dispatched transfers need a verified recovery scan.');
        $this->assertSame(0, $this->counts($f, $f['a']['station']->id)['inbound']);
    }

    public function test_non_logistics_roles_cannot_open_inventory_or_acknowledge_station_custody(): void
    {
        $f = $this->fixture();
        $url = route('logistics.delivery-monitoring.index', ['station' => $f['station']->id]);
        foreach ([['buyer', $f['buyer']], ['seller', $f['seller']], ['rider', $f['rider']]] as [$guard, $user]) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, $guard)->get($url)->assertRedirect();
            $this->post(route('logistics.returns.station-receipt', $f['return']), [
                'station_hub_id' => $f['station']->id, 'tracking_number' => $f['parcel']->tracking_number,
            ])->assertRedirect();
        }
        $this->assertSame(0, $f['return']->events()->where('event_key', 'station_received')->count());
    }

    private function databaseSnapshot(): array
    {
        // Financial and custody tables: compare all persisted values, not just row counts.
        $tables = ['seller_orders', 'parcel_returns', 'parcel_return_events', 'payments', 'seller_earnings',
            'seller_settlements', 'seller_debts', 'rider_earnings', 'logistics_earnings', 'logistics_return_charges',
            'platform_refund_expenses', 'cod_remittances', 'logistics_remittances'];
        $snapshot = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
        }

        return $snapshot;
    }
}
