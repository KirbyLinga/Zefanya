<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\Manifest;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class DashboardWorkQueuesTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    public function test_stalled_queue_excludes_recent_terminal_and_foreign_custody_and_respects_dates(): void
    {
        $this->freezeTime();
        $local = $this->operationalHandoffProvider('Local', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $other = $this->handoffProvider('Other');
        $expected = null;
        foreach ([[$local['provider'], OrderStatus::AtSortingCenter, 25], [$local['provider'], OrderStatus::AtSortingCenter, 1], [$local['provider'], OrderStatus::Completed, 25], [$other, OrderStatus::AtSortingCenter, 25]] as [$owner, $status, $age]) {
            $parcel = SellerOrder::factory()->create();
            $parcel->forceFill(['status' => $status, 'custody_provider_id' => $owner->id, 'destination_area_id' => $local['area']->id, 'updated_at' => now()->subHours($age)])->save();
            $expected ??= $parcel;
        }
        $this->actingAs($local['provider'], 'logistics')->get(route('logistics.dashboard', ['metric' => 'stalled', 'facility' => 'station:'.$local['station']->id]))
            ->assertOk()->assertViewHas('actionQueues', fn ($queues) => collect($queues)->keyBy('key')['stalled']['count'] === 1)
            ->assertViewHas('metricResults', fn ($rows) => $rows->pluck('id')->all() === [$expected->id]);
        $this->get(route('logistics.dashboard', ['metric' => 'stalled', 'from' => now()->addDay()->format('Y-m-d')]))
            ->assertOk()->assertViewHas('metricResults', fn ($rows) => $rows->isEmpty());
    }

    public function test_incoming_requests_and_outgoing_dispatch_are_visible_before_custody_changes(): void
    {
        $origin = $this->operationalHandoffProvider('Origin', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $destination = $this->operationalHandoffProvider('Destination', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'National Capital Region');
        $this->activeHandoffLink($origin['provider'], $destination['provider'], Admin::factory()->create());
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
        $parcel = $this->handoffParcel($seller, $buyer, $origin['provider']);
        $service = app(ParcelHandoffService::class);
        $handoff = $service->request($parcel, $origin['provider']);
        $this->actingAs($destination['provider'], 'logistics')->get(route('logistics.dashboard', ['facility' => 'station:'.$handoff->receiving_hub_id]))
            ->assertOk()->assertViewHas('actionQueues', fn ($queues) => collect($queues)->keyBy('key')['accept']['count'] === 1);
        $service->accept($handoff, $destination['provider']);
        $this->actingAs($origin['provider'], 'logistics')->get(route('logistics.dashboard'))
            ->assertOk()->assertViewHas('actionQueues', fn ($queues) => collect($queues)->keyBy('key')['dispatch']['count'] === 1);
        $this->assertNull($parcel->fresh()->custody_provider_id);
        $this->assertTrue(SellerOrder::query()->heldBy($origin['provider']->id)->whereKey($parcel->id)->exists());
        $this->assertFalse(SellerOrder::query()->heldBy($destination['provider']->id)->whereKey($parcel->id)->exists());
    }

    public function test_incoming_manifests_are_scoped_to_destination_facility_and_provider(): void
    {
        $local = $this->operationalHandoffProvider('Local', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $other = $this->operationalHandoffProvider('Other', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'National Capital Region');
        foreach ([[$local, ManifestStatus::Dispatched, false], [$local, ManifestStatus::Draft, false], [$local, ManifestStatus::Dispatched, true], [$other, ManifestStatus::Dispatched, false]] as [$owner, $status, $reverse]) {
            $manifest = new Manifest(['logistics_provider_id' => $owner['provider']->id, 'origin_hub_id' => $owner[$reverse ? 'station' : 'province']->id, 'destination_hub_id' => $owner[$reverse ? 'province' : 'station']->id]);
            $manifest->forceFill(['status' => $status])->save();
        }
        $this->actingAs($local['provider'], 'logistics')->get(route('logistics.dashboard', ['facility' => 'station:'.$local['station']->id]))
            ->assertOk()->assertViewHas('actionQueues', fn ($queues) => collect($queues)->keyBy('key')['transfers']['count'] === 1);
    }
}
