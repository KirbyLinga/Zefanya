<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Support\Facades\Storage;

trait BootstrapsLinehaulBreakdowns
{
    use BootstrapsCrossProviderHandoffs;

    protected function fixture(bool $depart = true): array
    {
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(\Carbon\Carbon::parse('2026-11-08T04:00:00Z'));
        $network = $this->operationalHandoffProvider('Breakdown Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Breakdown Courier', 'email' => 'breakdown@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        app(LinehaulDutyService::class)->change($driver, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)]);
        $parcel = SellerOrder::factory()->create(['tracking_number' => 'BREAKDOWN-001']);
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $network['station']->id,
            'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id, 'custody_provider_id' => null, 'handover_at' => now()])->save();
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);
        app(ManifestService::class)->addParcel($manifest, $parcel);
        $service = app(LinehaulService::class);
        $assignment = $service->assign($network['provider'], $manifest, $driver);
        $service->act($driver, $manifest, 'accept', [], request());
        $service->act($driver, $manifest, 'scan', ['tracking_number' => $parcel->tracking_number, 'method' => 'manual'], request());
        if ($depart) {
            $service->act($driver, $manifest, 'depart', ['seal_number' => 'BH-0000771'], request());
        }

        return [...$network, ...compact('driver', 'manifest', 'assignment', 'parcel')];
    }
}
