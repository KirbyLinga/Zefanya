<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InternalManifestDatabaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private LogisticsHub $origin;

    private LogisticsHub $destination;

    private SellerOrder $parcel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->createProvider();
        $province = $this->createProvinceHub($this->provider);

        $this->origin = $this->createStationHub($this->provider, $province, 'Origin Station', 'San Fernando', '030540100');
        $this->destination = $this->createStationHub($this->provider, $province, 'Destination Station', 'Angeles', '030540200');

        $seller = Seller::factory()->create();
        $order = Order::factory()->create();

        $this->parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'tracking_number' => 'TRK-MANIFEST-0001',
        ]);
    }

    public function test_manifest_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('manifests'));
        $this->assertTrue(Schema::hasTable('manifest_items'));

        foreach ([
            'logistics_provider_id',
            'origin_hub_id',
            'destination_hub_id',
            'status',
            'dispatched_at',
            'dispatched_by_type',
            'dispatched_by_id',
            'received_at',
            'received_by_type',
            'received_by_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('manifests', $column), "Missing manifests.{$column}");
        }

        foreach ([
            'manifest_id',
            'seller_order_id',
            'tracking_number',
            'received_at',
            'received_by_type',
            'received_by_id',
            'released_at',
            'active_seller_order_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('manifest_items', $column), "Missing manifest_items.{$column}");
        }
    }

    public function test_manifest_belongs_to_one_provider_and_identifies_both_hubs(): void
    {
        $manifest = $this->newManifest();

        $this->assertSame(ManifestStatus::Draft, $manifest->status);
        $this->assertTrue($manifest->isOpen());
        $this->assertSame($this->provider->id, $manifest->provider->id);
        $this->assertSame($this->origin->id, $manifest->originHub->id);
        $this->assertSame($this->destination->id, $manifest->destinationHub->id);
        $this->assertSame(1, $this->provider->fresh()->manifests()->count());
        $this->assertSame(1, $this->origin->fresh()->outgoingManifests()->count());
        $this->assertSame(1, $this->destination->fresh()->incomingManifests()->count());
    }

    public function test_manifest_item_links_parcel_and_snapshots_tracking_number(): void
    {
        $manifest = $this->newManifest();
        $item = $this->addItem($manifest, $this->parcel);

        $this->assertSame($manifest->id, $item->manifest->id);
        $this->assertSame($this->parcel->id, $item->sellerOrder->id);
        $this->assertSame('TRK-MANIFEST-0001', $item->tracking_number);
        $this->assertTrue($item->isActive());
        $this->assertFalse($item->isReceived());
        $this->assertSame(1, $this->parcel->fresh()->manifestItems()->count());

        // The manifest keeps the label that was issued when it was created.
        $this->parcel->update(['tracking_number' => 'TRK-MANIFEST-CHANGED']);
        $this->assertSame('TRK-MANIFEST-0001', $item->fresh()->tracking_number);
    }

    public function test_duplicate_active_manifest_membership_is_rejected(): void
    {
        $this->addItem($this->newManifest(), $this->parcel);

        $this->expectException(QueryException::class);

        $this->addItem($this->newManifest(), $this->parcel);
    }

    public function test_receipt_releases_membership_and_preserves_historical_items(): void
    {
        $first = $this->addItem($this->newManifest(), $this->parcel);
        $first->markReceived('logistics', $this->provider->id);

        $first->refresh();
        $this->assertTrue($first->isReceived());
        $this->assertFalse($first->isActive());
        $this->assertNotNull($first->received_at);
        $this->assertNotNull($first->released_at);
        $this->assertSame('logistics', $first->received_by_type);
        $this->assertSame($this->provider->id, (int) $first->received_by_id);
        $this->assertNull(DB::table('manifest_items')->where('id', $first->id)->value('active_seller_order_id'));

        // A later transfer may manifest the same parcel again.
        $second = $this->addItem($this->newManifest(), $this->parcel);

        $this->assertTrue($second->isActive());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($this->parcel->id, (int) DB::table('manifest_items')->where('id', $second->id)->value('active_seller_order_id'));
        $this->assertSame(2, ManifestItem::query()->where('seller_order_id', $this->parcel->id)->count());
    }

    public function test_partial_receipt_is_tracked_per_item_and_status_is_auditable(): void
    {
        $manifest = $this->newManifest();
        $first = $this->addItem($manifest, $this->parcel);

        $secondParcel = $this->secondParcel();
        $second = $this->addItem($manifest, $secondParcel);

        $manifest->forceFill([
            'status' => ManifestStatus::Dispatched->value,
            'dispatched_at' => now()->subHour(),
            'dispatched_by_type' => 'logistics',
            'dispatched_by_id' => $this->provider->id,
        ])->save();

        $first->markReceived('logistics', $this->provider->id);

        $manifest->refresh();
        $this->assertSame(ManifestStatus::Dispatched, $manifest->status);
        $this->assertNotNull($manifest->dispatched_at);
        $this->assertSame('logistics', $manifest->dispatched_by_type);
        $this->assertSame($this->provider->id, (int) $manifest->dispatched_by_id);
        $this->assertNull($manifest->received_at);
        $this->assertSame(1, $manifest->items()->whereNotNull('received_at')->count());
        $this->assertSame(1, $manifest->items()->whereNull('received_at')->count());

        $second->refresh();
        $this->assertFalse($second->isReceived());
        $this->assertTrue($second->isActive());

        $second->markReceived('logistics', $this->provider->id);
        $manifest->forceFill([
            'status' => ManifestStatus::Received->value,
            'received_at' => now(),
            'received_by_type' => 'logistics',
            'received_by_id' => $this->provider->id,
        ])->save();

        $manifest->refresh();
        $this->assertSame(ManifestStatus::Received, $manifest->status);
        $this->assertFalse($manifest->isOpen());
        $this->assertNotNull($manifest->received_at);
        $this->assertSame(0, $manifest->items()->whereNull('received_at')->count());
    }

    public function test_hubs_from_another_provider_are_rejected_by_foreign_key(): void
    {
        $otherProvider = $this->createProvider();
        $otherProvince = $this->createProvinceHub($otherProvider);
        $foreignHub = $this->createStationHub($otherProvider, $otherProvince, 'Foreign Station', 'Mabalacat', '030540300');

        $this->expectException(QueryException::class);

        $this->newManifest($foreignHub->id, $this->destination->id);
    }

    private function newManifest(?int $originId = null, ?int $destinationId = null): Manifest
    {
        return Manifest::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'origin_hub_id' => $originId ?? $this->origin->id,
            'destination_hub_id' => $destinationId ?? $this->destination->id,
        ]);
    }

    private function addItem(Manifest $manifest, SellerOrder $parcel): ManifestItem
    {
        return ManifestItem::query()->create([
            'manifest_id' => $manifest->id,
            'seller_order_id' => $parcel->id,
        ]);
    }

    private function secondParcel(): SellerOrder
    {
        $order = Order::factory()->create();

        return SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->parcel->seller_id,
            'tracking_number' => 'TRK-MANIFEST-0002',
        ]);
    }

    private function createProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create();
    }

    private function createProvinceHub(LogisticsProvider $provider): LogisticsHub
    {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => 'Province Hub '.$provider->id,
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provider->province_code ?: '030540000',
            'province_name' => $provider->province_name ?: 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);
    }

    private function createStationHub(
        LogisticsProvider $provider,
        LogisticsHub $province,
        string $name,
        string $municipality,
        string $municipalityCode,
    ): LogisticsHub {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $name,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'region_name' => $province->region_name,
            'province_code' => $province->province_code,
            'province_name' => $province->province_name,
            'municipality' => $municipality,
            'city_municipality_code' => $municipalityCode,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }
}
