<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\LogisticsHubService;
use App\Services\RiderOperationsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class BarangayReadinessTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    public function test_active_uncovered_station_warns_without_deactivating_or_stranding_existing_delivery(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = $this->assignableCourier($provider);
        $station = $courier->rider->station;
        $courier->area->update(['coverage_validated_at' => null, 'barangay_code' => null]);
        $this->assertTrue($station->fresh()->isOperational());
        $this->assertFalse($station->fresh()->isReadyForNewAssignments());
        $this->actingAs($provider, 'logistics')->get(route('logistics.hubs.index'))->assertOk()
            ->assertSee('Coverage required'."\u{2014}".'new assignments blocked');
        $parcel = SellerOrder::factory()->status(OrderStatus::AssignedToRider)->create();
        $parcel->forceFill(['delivery_courier_id' => $courier->id])->save();
        (new RiderOperationsService)->startDelivery($courier->rider, $parcel);
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertTrue($station->fresh()->is_active);
    }

    public function test_ready_station_requires_validated_active_coverage(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = $this->assignableCourier($provider);
        $station = $courier->rider->station;
        $this->assertTrue($station->fresh()->isReadyForNewAssignments());
        $courier->area->update(['is_active' => false]);
        $this->assertFalse($station->fresh()->isReadyForNewAssignments());
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
        ]);
        $this->assertNull((new CourierAssignmentService)->reservePickup($parcel));
        $this->assertNull($parcel->fresh()->courier_id);
    }

    public function test_unapplied_schema_shows_guidance_instead_of_unknown_column_errors(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->assignableCourier($provider);
        // Simulate a pre-deploy schema without dropping columns or data.
        $this->mock(LogisticsHubService::class, function ($mock): void {
            $mock->makePartial()->shouldReceive('coverageSchemaAvailable')->andReturn(false);
        });
        $this->actingAs($provider, 'logistics')->get(route('logistics.areas.index'))->assertOk()->assertSee('pending Barangay Coverage migrations');
        $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard'))->assertOk();
        $this->assertStringContainsString('pending Barangay Coverage migrations', app(LogisticsHubService::class)->assignmentBlockReason($provider->id));
    }
}
