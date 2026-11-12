<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsLinehaulBreakdowns;
use Tests\TestCase;

class LinehaulCustodyRecoveryTest extends TestCase
{
    use BootstrapsLinehaulBreakdowns, LazilyRefreshDatabase;

    public function test_suspended_courier_parcels_are_recovered_individually_without_inventing_arrival(): void
    {
        ['parcel' => $parcel, 'driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment,
            'provider' => $provider, 'station' => $station, 'province' => $province] = $this->fixture(false);
        $missing = SellerOrder::factory()->create(['tracking_number' => 'UNAVAILABLE-002']);
        $missing->forceFill([
            'status' => OrderStatus::AtSortingCenter, 'origin_station_hub_id' => $station->id,
            'custody_hub_id' => $station->id, 'carrier_provider_id' => $provider->id, 'handover_at' => now(),
        ])->save();
        $manifests = app(ManifestService::class);
        $missingItem = $manifests->addParcel($manifest, $missing);
        $linehaul = app(LinehaulService::class);
        $linehaul->act($driver, $manifest, 'scan', ['tracking_number' => $missing->tracking_number, 'method' => 'manual'], request());
        $linehaul->act($driver, $manifest, 'depart', ['seal_number' => 'BH-0000771'], request());
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->withToken($driver->createToken('former-courier', ['linehaul'])->plainTextToken)
            ->postJson('/api/courier/trips/'.$manifest->id.'/arrive')->assertForbidden();

        $incidents = app(ParcelIncidentService::class);
        $report = ['type' => 'worker_unavailable', 'reason' => 'Courier suspended in transit.', 'recovery_owner' => 'Hub lead'];
        $case = $incidents->reportOperational($parcel, $provider, $report);
        $missingCase = $incidents->reportOperational($missing, $provider, $report);
        $this->assertSame($driver->id, $case->custody_snapshot['worker_id']);
        $incidents->review($case, $provider, 'One parcel recovered at the destination hub.');

        $this->actingAs($provider, 'logistics');
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee(route('logistics.delivery-monitoring.show', $missing).'#operational-exception');
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'receive', 'method' => 'manual',
        ])->assertOk();
        $item = $manifest->items()->where('seller_order_id', $parcel->id)->firstOrFail();
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $scan->json('verification_token'), 'observed_seal' => 'BH-0000771',
        ])->assertRedirect(route('logistics.manifests.receive.index', $manifest))->assertSessionHasNoErrors();

        $this->assertSame($province->id, $parcel->fresh()->custody_hub_id);
        $this->assertTrue($case->events()->where('event', 'recovery_received')->exists());
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertNull($assignment->fresh()->arrived_at);
        $this->assertNull($missingItem->fresh()->received_at);
        $this->assertNull($missingItem->fresh()->released_at);
        $this->assertTrue($missingCase->fresh()->isActive());

        $incidents->resolveForProvider($case, $provider, ['event' => ParcelIncidentService::RESUME, 'reason' => 'Verified hub receipt.']);
        $this->assertFalse($case->fresh()->isActive());
        $this->assertTrue($missingCase->fresh()->isActive());
    }
}
