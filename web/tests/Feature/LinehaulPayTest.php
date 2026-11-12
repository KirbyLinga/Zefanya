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

    public function test_geofence_blocks_departure_and_arrival_without_a_nearby_accurate_point(): void
    {
        $f = $this->fixture();
        $manifest = $f['manifest'];
        $tripPath = '/api/courier/trips/'.$manifest->id;
        $this->withToken($f['driver']->createToken('geofence-test', ['linehaul'])->plainTextToken);
        $seal = ['seal_number' => 'BH-0000771'];
        $origin = ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10];
        $destination = ['latitude' => 14.3, 'longitude' => 121.4, 'accuracy' => 10];

        config(['linehaul.require_hub_geofence' => true, 'linehaul.hub_coordinates' => []]);
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.departure_location', 'setup_required');
        $this->postJson($tripPath.'/depart', [...$seal, 'location' => $origin])->assertUnprocessable()->assertJsonValidationErrors('location');

        config(['linehaul.hub_coordinates' => [
            $manifest->origin_hub_id => ['latitude' => 14.2, 'longitude' => 121.3],
            $manifest->destination_hub_id => ['latitude' => 14.3, 'longitude' => 121.4],
        ]]);
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.departure_location', 'required');
        $this->postJson($tripPath.'/depart', $seal)->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->postJson($tripPath.'/depart', [...$seal, 'location' => [...$origin, 'accuracy' => 101]])
            ->assertUnprocessable()->assertJsonValidationErrors('location.accuracy');
        $this->postJson($tripPath.'/depart', [...$seal, 'location' => [...$origin, 'latitude' => 14.205]])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);
        $this->assertNull($f['assignment']->fresh()->trip_pay_minor);

        $this->postJson($tripPath.'/depart', [...$seal, 'location' => [...$origin, 'latitude' => 14.2044]])
            ->assertOk()->assertJsonPath('data.arrival_location', 'required');
        $this->postJson($tripPath.'/arrive', ['location' => $origin])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertNull($f['assignment']->fresh()->arrived_at);
        $this->postJson($tripPath.'/arrive', ['location' => $destination])->assertOk()->assertJsonPath('data.status', 'Arrived');
        $this->postJson($tripPath.'/arrive')->assertOk()->assertJsonPath('data.status', 'Arrived');
        $this->assertDatabaseHas('manifest_audit_logs', ['manifest_id' => $manifest->id, 'action' => 'courier_arrive', 'verification_method' => 'hub_geofence']);
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

    public function test_reviewed_van_rate_is_frozen_at_departure(): void
    {
        $f = $this->fixture('Van');
        $this->assertSame(['status' => 'quote', 'amount_minor' => 60000], LinehaulPayService::details($f['assignment']->fresh()));

        $this->depart($f);
        $f['profile']->forceFill(['vehicle_type' => '6-Wheeler Truck'])->save();

        $this->assertSame('Van', $f['assignment']->fresh()->trip_pay_vehicle_type);
        $this->assertSame(['status' => 'pending', 'amount_minor' => 60000], LinehaulPayService::details($f['assignment']->fresh()));

        $this->actingAs($f['provider'], 'logistics');
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $f['manifest']), [
            'tracking_number' => $f['parcel']->tracking_number, 'phase' => 'receive', 'method' => 'manual',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$f['manifest'], $f['manifest']->items()->sole()]), [
            'verification_token' => $scan, 'observed_seal' => 'BH-0000771',
        ])->assertRedirect();

        $this->assertSame(['status' => 'earned', 'amount_minor' => 60000], LinehaulPayService::details($f['assignment']->fresh()));
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

    public function test_ten_wheeler_uses_only_an_explicitly_configured_rate(): void
    {
        $f = $this->fixture('10-Wheeler Truck');
        config(['linehaul.ten_wheeler_pay_minor' => null]);
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
        config(['linehaul.ten_wheeler_pay_minor' => '200000']);
        $this->assertSame(200000, LinehaulPayService::details($f['assignment']->fresh())['amount_minor']);
        $this->depart($f);
        config(['linehaul.ten_wheeler_pay_minor' => '250000']);
        $this->assertSame(200000, LinehaulPayService::details($f['assignment']->fresh())['amount_minor']);
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

    public function test_company_can_record_confirmed_trip_payment_once_and_courier_sees_paid_date(): void
    {
        $f = $this->fixture();
        $url = route('logistics.manifests.courier.payout', $f['manifest']);
        $date = now('Asia/Manila')->toDateString();
        $payment = ['expected_amount_minor' => 100000, 'reference' => 'TRANSFER-102', 'paid_on' => $date];
        $this->postJson($url, $payment)->assertUnauthorized();
        $other = $this->handoffProvider('Other Pay Carrier');
        $this->actingAs($other, 'logistics')->postJson($url, $payment)->assertForbidden();
        $this->actingAs($f['provider'], 'logistics')->postJson($url, $payment)->assertUnprocessable();

        $this->depart($f);
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $f['manifest']),
            ['tracking_number' => $f['parcel']->tracking_number, 'phase' => 'receive', 'method' => 'manual'])
            ->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$f['manifest'], $f['manifest']->items()->sole()]),
            ['verification_token' => $scan, 'observed_seal' => 'BH-0000771'])->assertRedirect();
        $this->postJson($url, [...$payment, 'expected_amount_minor' => 1])->assertUnprocessable();
        $this->postJson($url, [...$payment, 'amount_minor' => 1])->assertUnprocessable();
        $this->postJson($url, $payment)->assertCreated()->assertJsonPath('data.paid_on', $date)
            ->assertJsonPath('data.amount_minor', 100000)->assertJsonMissingPath('data.reference');
        $this->postJson($url, $payment)->assertOk();
        $this->postJson($url, [...$payment, 'reference' => 'DIFFERENT'])->assertUnprocessable();
        $this->assertDatabaseCount('linehaul_trip_payouts', 1);
        $this->assertDatabaseHas('linehaul_trip_payouts', ['linehaul_assignment_id' => $f['assignment']->id,
            'linehaul_driver_id' => $f['driver']->id, 'reference' => 'TRANSFER-102']);
        $this->assertSame(1, \App\Models\Logistics\ManifestAuditLog::where('manifest_id', $f['manifest']->id)
            ->where('action', 'courier_payout_recorded')->count());

        $this->app['auth']->forgetGuards();
        $token = $f['driver']->createToken('payout-test', ['linehaul'])->plainTextToken;
        $this->withToken($token)->getJson('/api/courier/earnings?from='.$date.'&to='.$date)
            ->assertOk()->assertJsonPath('data.payout_recording_available', true)
            ->assertJsonPath('data.earned_minor', 100000)
            ->assertJsonPath('data.trips.0.paid_on', $date)->assertJsonMissingPath('data.trips.0.reference');
    }

    public function test_audited_correction_and_reversal_preserve_frozen_pay_and_original_payment(): void
    {
        $f = $this->fixture();
        $manifest = $f['manifest'];
        $this->actingAs($f['provider'], 'logistics');
        $this->depart($f);
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $f['parcel']->tracking_number, 'phase' => 'receive', 'method' => 'manual',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $manifest->items()->sole()]), [
            'verification_token' => $scan, 'observed_seal' => 'BH-0000771',
        ])->assertSessionHasNoErrors();

        $date = now('Asia/Manila')->toDateString();
        $pay = route('logistics.manifests.courier.payout', $manifest);
        $correct = route('logistics.manifests.courier.payout.correction', $manifest);
        $payment = ['expected_amount_minor' => 100000, 'reference' => 'BANK-ORIGINAL', 'paid_on' => $date];
        $this->postJson($pay, $payment)->assertCreated();
        $payout = $f['assignment']->payout()->sole();
        $data = [
            'payout_id' => $payout->id, 'action' => 'reference', 'expected_reference' => 'BANK-ORIGINAL',
            'reference' => 'BANK-CORRECTED', 'staff_name' => 'Finance lead', 'reason' => 'Bank statement confirms the reference.',
        ];
        $this->actingAs($this->handoffProvider('Other Pay Company'), 'logistics')->postJson($correct, $data)->assertForbidden();
        $this->actingAs($f['provider'], 'logistics')->post($correct, $data)->assertSessionHas('success');
        $this->postJson($correct, $data)->assertUnprocessable();
        $this->assertSame('BANK-ORIGINAL', $payout->fresh()->reference);
        $this->assertSame('BANK-CORRECTED', $payout->fresh()->recordedReference());
        $this->assertSame($payout->id, $f['assignment']->payout()->sole()->id);
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('BANK-CORRECTED')->assertSee('BANK-ORIGINAL')->assertSee('Finance lead');

        $failed = [...$data, 'action' => 'failed', 'expected_reference' => 'BANK-CORRECTED',
            'reference' => 'BANK-FAIL-91', 'reason' => 'Bank confirmed the transfer was bounced.'];
        $this->postJson($correct, $failed)->assertUnprocessable()->assertJsonValidationErrors('external_failure_confirmed');
        $failed['external_failure_confirmed'] = '1';
        $this->post($correct, $failed)->assertSessionHas('success');
        $this->postJson($correct, $failed)->assertUnprocessable();
        $this->assertNull($f['assignment']->payout()->first());
        $this->assertSame(100000, $f['assignment']->fresh()->trip_pay_minor);
        $this->assertSame($f['provider']->id, $payout->reversal()->sole()->logistics_provider_id);
        $this->assertDatabaseCount('linehaul_trip_payouts', 1);
        $this->assertDatabaseCount('linehaul_trip_payout_corrections', 2);

        $this->postJson($pay, $payment)->assertUnprocessable();
        $replacement = [...$payment, 'replaces_payout_id' => $payout->id, 'reference' => 'BANK-REPLACEMENT'];
        $this->postJson($pay, $replacement)->assertCreated();
        $this->postJson($pay, $replacement)->assertOk();
        $this->assertSame('BANK-REPLACEMENT', $f['assignment']->payout()->sole()->reference);
        $this->assertDatabaseCount('linehaul_trip_payouts', 2);
        $this->assertDatabaseHas('linehaul_trip_payouts', ['id' => $payout->id, 'reference' => 'BANK-ORIGINAL', 'amount_minor' => 100000]);
    }

    public function test_trip_shows_shipping_ceiling_without_changing_dispatch_or_earnings(): void
    {
        $f = $this->fixture();
        $manifest = $f['manifest'];
        $f['parcel']->forceFill(['shipping_fee_minor' => 0])->save();
        $show = route('logistics.manifests.show', $manifest);
        $this->actingAs($f['provider'], 'logistics')->get($show)->assertOk()
            ->assertSee('Estimated Courier rate')->assertSee('Shipping allocation is unavailable')
            ->assertDontSee('Potential shortfall');
        $f['parcel']->forceFill(['shipping_fee_minor' => 125000])->save();
        $this->get($show)->assertOk()->assertSee('₱1,250.00')
            ->assertSee('The share for this trip is unavailable')->assertDontSee('Potential shortfall');
        $f['parcel']->forceFill(['shipping_fee_minor' => 30000])->save();
        $this->get($show)->assertOk()->assertSee('Whole-shipment shipping allocation')
            ->assertSee('₱300.00')->assertSee('Potential shortfall');
        $this->depart($f);
        $this->get($show)->assertOk()->assertSee('Recorded Courier rate, awaiting handover')
            ->assertSee('Potential shortfall');
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertSame(100000, $f['assignment']->fresh()->trip_pay_minor);
    }
}
