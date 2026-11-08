<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulProfile;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulPayService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulPayTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(string $vehicle = '4-Wheeler Closed Van Truck'): array
    {
        $this->withoutVite();
        $network = $this->operationalHandoffProvider('Pay Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Pay Courier', 'email' => 'pay@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        app(\App\Services\Logistics\LinehaulDutyService::class)->change($driver, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(\App\Services\Logistics\LinehaulDutyService::ITEMS, true)]);
        $profile = new LinehaulProfile;
        $profile->forceFill(['linehaul_driver_id' => $driver->id, 'plate_number' => 'ABC1234', 'license_no' => 'N01-12-123456',
            'license_expiry' => now()->addYear()->toDateString(), 'license_codes' => ['B'], 'vehicle_type' => $vehicle,
            'capacity_kg' => '1000.00', 'documents' => [], 'review_status' => 'approved', 'version' => 1])->save();
        $parcel = SellerOrder::factory()->create(['tracking_number' => 'PAY-001', 'parcel_weight_grams' => 800]);
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $network['station']->id,
            'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id, 'custody_provider_id' => null, 'handover_at' => now()])->save();
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);
        app(ManifestService::class)->addParcel($manifest, $parcel);
        $assignment = app(LinehaulService::class)->assign($network['provider'], $manifest, $driver);
        app(LinehaulService::class)->act($driver, $manifest, 'accept', [], request());
        app(LinehaulService::class)->act($driver, $manifest, 'scan', ['tracking_number' => $parcel->tracking_number, 'method' => 'manual'], request());

        return [...$network, ...compact('driver', 'profile', 'parcel', 'manifest', 'assignment')];
    }

    private function depart(array $fixture): void
    {
        app(LinehaulService::class)->act($fixture['driver'], $fixture['manifest'], 'depart', ['seal_number' => 'BH-0000771'], request());
    }

    public function test_supported_rates_are_server_quotes_then_frozen_at_actual_departure(): void
    {
        $f = $this->fixture();
        $this->assertSame(['status' => 'quote', 'amount_minor' => 100000], LinehaulPayService::details($f['assignment']->fresh()));
        $this->depart($f);
        $this->assertSame('4-Wheeler Closed Van Truck', $f['assignment']->fresh()->trip_pay_vehicle_type);
        $f['profile']->forceFill(['vehicle_type' => '6-Wheeler Truck'])->save();
        $this->assertSame(['status' => 'pending', 'amount_minor' => 100000], LinehaulPayService::details($f['assignment']->fresh()));
        LinehaulPayService::freeze($f['driver'], $f['assignment']->fresh());
        $this->assertSame(100000, $f['assignment']->fresh()->trip_pay_minor);
    }

    public function test_six_wheeler_rate_and_unknown_type_are_not_guessed(): void
    {
        $f = $this->fixture('6-Wheeler Truck');
        $this->assertSame(150000, LinehaulPayService::details($f['assignment']->fresh())['amount_minor']);
        $f['profile']->forceFill(['vehicle_type' => '10-Wheeler Truck'])->save();
        $this->assertSame(['status' => 'not_configured', 'amount_minor' => null], LinehaulPayService::details($f['assignment']->fresh()));
        $this->depart($f);
        $this->assertNull($f['assignment']->fresh()->trip_pay_minor);
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
    }

    public function test_unreviewed_or_expired_vehicle_cannot_advertise_pay_quote(): void
    {
        $f = $this->fixture();
        $f['profile']->forceFill(['review_status' => 'pending'])->save();
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
        $f['profile']->forceFill(['review_status' => 'approved', 'license_expiry' => now()->subDay()->toDateString()])->save();
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
    }

    public function test_pay_is_earned_only_for_same_driver_after_recorded_logistics_handover(): void
    {
        $f = $this->fixture(); $this->depart($f);
        $manifest = $f['manifest']->fresh();
        $manifest->forceFill(['status' => ManifestStatus::Received, 'received_at' => now(), 'received_by_type' => 'logistics', 'received_by_id' => $f['provider']->id])->save();
        $this->assertSame('pending', LinehaulPayService::details($f['assignment']->fresh())['status']);
        $f['assignment']->refresh()->forceFill(['seal_receipt' => ['mismatch' => false]])->save();
        foreach ([ManifestStatus::Received, ManifestStatus::ReceivedWithExceptions] as $status) {
            $manifest->forceFill(['status' => $status])->save();
            $this->assertSame(['status' => 'earned', 'amount_minor' => 100000], LinehaulPayService::details($f['assignment']->fresh()));
        }
        $manifest->forceFill(['received_by_id' => 99999])->save();
        $this->assertSame('pending', LinehaulPayService::details($f['assignment']->fresh())['status']);
        $manifest->forceFill(['received_by_id' => $f['provider']->id, 'dispatched_by_id' => 99999])->save();
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
    }

    public function test_unknown_legacy_completion_is_not_repriced_and_cancelled_or_declined_earns_zero(): void
    {
        $f = $this->fixture();
        $f['manifest']->forceFill(['status' => ManifestStatus::Received])->save();
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
        $f['manifest']->forceFill(['status' => ManifestStatus::Cancelled])->save();
        $this->assertSame(['status' => 'ineligible', 'amount_minor' => 0], LinehaulPayService::details($f['assignment']->fresh()));
        $f['manifest']->forceFill(['status' => ManifestStatus::Draft])->save();
        $f['assignment']->forceFill(['declined_at' => now()])->save();
        $this->assertSame(['status' => 'ineligible', 'amount_minor' => 0], LinehaulPayService::details($f['assignment']->fresh()));
    }

    public function test_failed_departure_rolls_back_snapshot_and_trip_reads_remain_private(): void
    {
        $f = $this->fixture();
        $f['assignment']->refresh()->forceFill(['loading_tokens' => null])->save();
        try {
            $this->depart($f);
            $this->fail('Unscanned departure accepted.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertNull($f['assignment']->fresh()->trip_pay_minor);
        }
        $token = $f['driver']->createToken('pay-test', ['linehaul'])->plainTextToken;
        $this->withToken($token)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.trip_pay.status', 'quote')->assertJsonPath('data.0.trip_pay.amount_minor', 100000);
        $other = $f['driver']->replicate()->forceFill(['email' => 'other-pay@example.com']); $other->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('pay-test', ['linehaul'])->plainTextToken)->getJson('/api/courier/trips')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_native_handover_earns_frozen_rate_once_and_client_cannot_choose_amount(): void
    {
        $f = $this->fixture();
        $token = $f['driver']->createToken('pay-test', ['linehaul'])->plainTextToken;
        $this->withToken($token)->postJson('/api/courier/trips/'.$f['manifest']->id.'/depart',
            ['seal_number' => 'BH-0000771', 'trip_pay_minor' => 99999999])->assertOk()->assertJsonPath('data.trip_pay.amount_minor', 100000);
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->actingAs($f['provider'], 'logistics');
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $f['manifest']),
            ['tracking_number' => $f['parcel']->tracking_number, 'phase' => 'receive', 'method' => 'manual'])->assertOk()->json('verification_token');
        $item = $f['manifest']->items()->sole();
        $this->post(route('logistics.manifests.receive.store', [$f['manifest'], $item]),
            ['verification_token' => $scan, 'observed_seal' => 'BH-0000771'])->assertRedirect();
        $before = $f['assignment']->fresh()->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(['status' => 'earned', 'amount_minor' => 100000], LinehaulPayService::details($f['assignment']->fresh()));
        }
        $this->assertSame($before, $f['assignment']->fresh()->getAttributes());
    }
}
