<?php

namespace Tests\Feature;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulProfile;
use App\Models\Logistics\LinehaulTransfer;
use App\Models\Logistics\ManifestAuditLog;
use App\Services\Logistics\LinehaulBreakdownService;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulPayService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\LinehaulTransferService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsLinehaulBreakdowns;
use Tests\TestCase;

class LinehaulTransferTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsLinehaulBreakdowns;
    private function transferFixture(): array
    {
        $f = $this->fixture(false);
        $f['parcel']->forceFill(['parcel_weight_grams' => 800])->save();
        $secondParcel = \App\Models\Shared\SellerOrder::factory()->create(['tracking_number' => 'TRANSFER-002', 'parcel_weight_grams' => 800]);
        $secondParcel->forceFill(['status' => \App\Enums\OrderStatus::AtSortingCenter->value,
            'origin_station_hub_id' => $f['station']->id, 'custody_hub_id' => $f['station']->id,
            'carrier_provider_id' => $f['provider']->id, 'custody_provider_id' => null, 'handover_at' => now()])->save();
        app(\App\Services\Logistics\ManifestService::class)->addParcel($f['manifest'], $secondParcel);
        app(LinehaulService::class)->act($f['driver'], $f['manifest'], 'scan', ['tracking_number' => $secondParcel->tracking_number, 'method' => 'manual'], request());
        $originalProfile = new LinehaulProfile;
        $originalProfile->forceFill(['linehaul_driver_id' => $f['driver']->id, 'plate_number' => 'OLD1234', 'license_no' => 'N01-12-123456',
            'license_expiry' => now()->addYear()->toDateString(), 'license_codes' => ['C'], 'vehicle_type' => '4-Wheeler Closed Van Truck',
            'capacity_kg' => '1000.00', 'documents' => [], 'review_status' => 'approved', 'version' => 1])->save();
        app(LinehaulService::class)->act($f['driver'], $f['manifest'], 'depart', ['seal_number' => 'BH-0000771'], request());
        $replacement = $f['driver']->replicate()->forceFill(['name' => 'Replacement Courier', 'email' => 'replacement@example.com',
            'on_duty' => false, 'duty_checked_at' => null, 'last_seen_at' => null, 'duty_location' => null]);
        $replacement->save();
        app(LinehaulDutyService::class)->change($replacement, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)]);
        $replacementProfile = new LinehaulProfile;
        $replacementProfile->forceFill(['linehaul_driver_id' => $replacement->id, 'plate_number' => 'NEW1234', 'license_no' => 'N02-12-123456',
            'license_expiry' => now()->addYear()->toDateString(), 'license_codes' => ['C'], 'vehicle_type' => '6-Wheeler Truck',
            'capacity_kg' => '1000.00', 'documents' => [], 'review_status' => 'approved', 'version' => 1])->save();
        $report = app(LinehaulBreakdownService::class)->report($f['driver'], $f['manifest'], ['reason' => 'Engine stopped',
            'new_eta' => now()->addHour()->toIso8601String(), 'offer_version' => 1, 'photo' => UploadedFile::fake()->image('engine.jpg')], request());

        return [...$f, ...compact('originalProfile', 'replacement', 'replacementProfile', 'report', 'secondParcel')];
    }

    private function transferData(array $f): array
    {
        return ['driver_id' => $f['replacement']->id, 'breakdown_id' => $f['report']->id, 'offer_version' => 1, 'reason' => 'Recovery after engine failure'];
    }

    private function transfer(array $f): LinehaulTransfer
    {
        return app(LinehaulTransferService::class)->reassign($f['provider'], $f['manifest'], $this->transferData($f), request());
    }

    private function transferApi(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('transfer-test', ['linehaul'])->plainTextToken);
    }

    private function takeoverUrl(array $f, string $action): string
    {
        return '/api/courier/trips/'.$f['manifest']->id.'/'.$action;
    }

    public function test_reassignment_preserves_native_dispatch_and_custody_but_revokes_old_driver_and_pay(): void
    {
        $f = $this->transferFixture();
        $before = [$f['manifest']->fresh()->getRawOriginal(), $f['parcel']->fresh()->getRawOriginal()];
        $transfer = $this->transfer($f);
        $assignment = $f['assignment']->fresh();
        $this->assertSame($before, [$f['manifest']->fresh()->getRawOriginal(), $f['parcel']->fresh()->getRawOriginal()]);
        $this->assertSame($f['replacement']->id, $assignment->linehaul_driver_id);
        $this->assertSame(2, $assignment->offer_version);
        $this->assertNull($assignment->trip_pay_minor);
        $this->assertNull($assignment->accepted_at);
        $this->assertSame([], $assignment->loading_tokens);
        $row = (new \App\Http\Resources\Mobile\LinehaulTransferResource($transfer))->resolve(request());
        $this->assertSame('Reassigned', $row['status']);
        $this->assertSame(['status' => 'ineligible', 'amount_minor' => 0], $row['trip_pay']);
        $this->assertSame([], $row['items']);
        $this->assertArrayNotHasKey('_dispatch_actor_id', $row);
        $this->transferApi($f['driver'])->postJson($this->takeoverUrl($f, 'arrive'))->assertForbidden();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'Reassigned')->assertJsonPath('data.0.trip_pay.amount_minor', 0);
        $this->getJson('/api/courier/history')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.trips.0.history_key', 'transfer:'.$transfer->id)
            ->assertJsonPath('data.trips.0.reassignment_reason', 'Recovery after engine failure')
            ->assertJsonPath('data.trips.0.items', []);
        $this->transferApi($f['replacement'])->getJson('/api/courier/trips')->assertOk()
            ->assertJsonPath('data.0.needs_rescan', true)->assertJsonPath('data.0.items.0.loaded', false)->assertJsonPath('data.0.can_report_delay', false);
        $this->getJson('/api/courier/history')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_every_parcel_must_be_rescanned_before_continue_arrival_or_native_receiving(): void
    {
        $f = $this->transferFixture(); $this->transfer($f);
        $this->transferApi($f['replacement']);
        $this->postJson($this->takeoverUrl($f, 'arrive'))->assertUnprocessable();
        $this->postJson($this->takeoverUrl($f, 'takeover-continue'), ['offer_version' => 2])->assertUnprocessable();
        $this->postJson($this->takeoverUrl($f, 'takeover-scan'), ['offer_version' => 2, 'tracking_number' => 'OTHER'])->assertUnprocessable();
        try {
            app(\App\Services\Logistics\LinehaulSealService::class)->confirm($f['manifest']->fresh(), $f['provider'],
                \Illuminate\Http\Request::create('/', 'POST', ['observed_seal' => 'BH-0000771']));
            $this->fail('Unrescanned takeover reached receiving.');
        } catch (ValidationException) {
            $this->assertNull($f['assignment']->fresh()->seal_receipt);
        }
        $scan = ['offer_version' => 2, 'tracking_number' => $f['parcel']->tracking_number, 'method' => 'camera'];
        $this->postJson($this->takeoverUrl($f, 'takeover-scan'), $scan)->assertOk()->assertJsonPath('data.items.0.loaded', true);
        $this->postJson($this->takeoverUrl($f, 'takeover-scan'), $scan)->assertOk();
        $this->postJson($this->takeoverUrl($f, 'takeover-continue'), ['offer_version' => 2])->assertUnprocessable();
        $this->postJson($this->takeoverUrl($f, 'takeover-scan'), [...$scan, 'tracking_number' => $f['secondParcel']->tracking_number])->assertOk();
        $this->postJson($this->takeoverUrl($f, 'takeover-continue'), ['offer_version' => 1])->assertUnprocessable();
        $this->postJson($this->takeoverUrl($f, 'takeover-continue'), ['offer_version' => 2])->assertOk()
            ->assertJsonPath('data.needs_rescan', false)->assertJsonPath('data.trip_pay.amount_minor', 150000);
        $this->postJson($this->takeoverUrl($f, 'takeover-continue'), ['offer_version' => 2])->assertOk();
        $this->assertSame(2, ManifestAuditLog::where('action', 'courier_takeover-scan')->count());
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_takeover-continue')->count());
        $this->postJson($this->takeoverUrl($f, 'arrive'))->assertOk()->assertJsonPath('data.status', 'Arrived');
    }

    public function test_only_finishing_courier_earns_new_frozen_rate_after_native_handover(): void
    {
        $f = $this->transferFixture(); $this->transfer($f);
        $service = app(LinehaulService::class);
        $service->act($f['replacement'], $f['manifest'], 'takeover-scan', ['offer_version' => 2, 'tracking_number' => $f['parcel']->tracking_number], request());
        $service->act($f['replacement'], $f['manifest'], 'takeover-scan', ['offer_version' => 2, 'tracking_number' => $f['secondParcel']->tracking_number], request());
        $service->act($f['replacement'], $f['manifest'], 'takeover-continue', ['offer_version' => 2], request());
        $f['replacementProfile']->forceFill(['vehicle_type' => '4-Wheeler Closed Van Truck'])->save();
        $this->assertSame(['status' => 'pending', 'amount_minor' => 150000], LinehaulPayService::details($f['assignment']->fresh()));
        $this->flushHeaders(); $this->app['auth']->forgetGuards(); $this->actingAs($f['provider'], 'logistics');
        foreach ($f['manifest']->items()->get() as $item) {
            $token = $this->postJson(route('logistics.manifests.scan.verify', $f['manifest']), ['tracking_number' => $item->tracking_number, 'phase' => 'receive', 'method' => 'manual'])->assertOk()->json('verification_token');
            $this->post(route('logistics.manifests.receive.store', [$f['manifest'], $item]),
                ['verification_token' => $token, 'observed_seal' => 'BH-0000771'])->assertRedirect();
        }
        $this->assertSame(['status' => 'earned', 'amount_minor' => 150000], LinehaulPayService::details($f['assignment']->fresh()));
        $this->assertSame($f['driver']->id, $f['manifest']->fresh()->dispatched_by_id);
        $f['manifest']->refresh()->forceFill(['dispatched_by_id' => 99999])->save();
        $this->assertSame('not_configured', LinehaulPayService::details($f['assignment']->fresh())['status']);
    }

    public function test_replacement_readiness_capacity_version_and_evidence_cannot_be_bypassed(): void
    {
        $f = $this->transferFixture();
        $data = $this->transferData($f);
        foreach ([['driver_id' => $f['driver']->id], ['breakdown_id' => 999999], ['offer_version' => 2], ['reason' => ' '], ['reason' => str_repeat('x', 501)]] as $bad) {
            try {
                app(LinehaulTransferService::class)->reassign($f['provider'], $f['manifest'], [...$data, ...$bad], request());
                $this->fail('Invalid reassignment accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, LinehaulTransfer::count());
            }
        }
        foreach ([['on_duty' => false], ['last_seen_at' => now()->subMinutes(6)], ['status' => 'suspended']] as $bad) {
            $driver = $f['replacement']->fresh(); $before = $driver->getAttributes(); $driver->forceFill($bad)->save();
            try {
                $this->transfer($f); $this->fail('Unready replacement accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, LinehaulTransfer::count());
            } finally {
                $driver->forceFill($before)->save();
            }
        }
        $f['replacementProfile']->forceFill(['capacity_kg' => '0.10'])->save();
        try {
            $this->transfer($f); $this->fail('Overweight replacement accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, LinehaulTransfer::count());
        }
        $this->assertSame($f['driver']->id, $f['assignment']->fresh()->linehaul_driver_id);
    }

    public function test_exact_reassignment_retry_is_stable_and_failed_audit_rolls_back_driver_change(): void
    {
        $f = $this->transferFixture(); $before = $f['assignment']->fresh()->getRawOriginal();
        ManifestAuditLog::creating(function ($audit): void {
            if ($audit->action === 'courier_reassign') { throw new \RuntimeException('Simulated reassignment audit failure'); }
        });
        try {
            $this->transfer($f); $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated reassignment audit failure', $error->getMessage());
        } finally {
            ManifestAuditLog::flushEventListeners();
        }
        $this->assertSame($before, $f['assignment']->fresh()->getRawOriginal());
        $this->assertSame(0, LinehaulTransfer::count());
        $first = $this->transfer($f); $again = $this->transfer($f);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, LinehaulTransfer::count());
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_reassign')->count());
        try { $first->forceFill(['reason' => 'forged'])->save(); $this->fail('Transfer history edited.'); }
        catch (\LogicException) { $this->assertSame('Recovery after engine failure', $first->fresh()->reason); }
    }

    public function test_new_routes_require_correct_role_company_validation_and_throttles(): void
    {
        $f = $this->transferFixture(); $url = route('logistics.manifests.courier.reassign', $f['manifest']);
        $this->postJson($url, $this->transferData($f))->assertUnauthorized();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $other->forceFill(['status' => \App\Enums\LogisticsProviderStatus::Approved])->save();
        $this->actingAs($other, 'logistics')->postJson($url, $this->transferData($f))->assertForbidden();
        $this->actingAs($f['provider'], 'logistics')->postJson($url, [])->assertUnprocessable();
        $show = route('logistics.manifests.show', $f['manifest']);
        $this->get($show)->assertOk()->assertSee('Reassign Courier')->assertSee('Replacement Courier');
        $this->postJson($url, [...$this->transferData($f), 'trip_pay_minor' => 999999])->assertUnprocessable();
        $this->postJson($url, $this->transferData($f))->assertCreated()->assertJsonPath('data.status', 'Reassigned');
        $this->get($show)->assertOk()->assertSee('Replacement Courier must rescan every parcel')->assertDontSee('Reassign Courier');
        for ($index = 0; $index < 21; $index++) { $response = $this->postJson($url, []); }
        $response->assertTooManyRequests();
        $buyer = \App\Models\Buyer\Buyer::factory()->create(); $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->postJson($this->takeoverUrl($f, 'takeover-scan'), [])->assertForbidden();
        $this->transferApi($f['replacement'])->postJson($this->takeoverUrl($f, 'takeover-scan'), [])->assertUnprocessable();
        for ($index = 0; $index < 61; $index++) { $response = $this->postJson($this->takeoverUrl($f, 'takeover-scan'), []); }
        $response->assertTooManyRequests();
    }

    public function test_logistics_trip_location_tracks_only_the_current_leg_and_marks_old_reports_stale(): void
    {
        $f = $this->transferFixture();
        $owner = $f['provider'];
        $manifest = $f['manifest'];
        $show = route('logistics.manifests.show', $manifest);
        $duty = app(LinehaulDutyService::class);
        $firstPoint = 'Latitude 14.12345, longitude 121.12345';
        $replacementPoint = 'Latitude 14.54321, longitude 121.54321';

        $this->travel(1)->seconds();
        $duty->change($f['driver'], 'heartbeat', ['latitude' => 14.12345, 'longitude' => 121.12345, 'accuracy' => 18]);
        $duty->change($f['replacement'], 'heartbeat', ['latitude' => 14.54321, 'longitude' => 121.54321, 'accuracy' => 10]);

        $this->actingAs($owner, 'logistics')->get($show)->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertSee('Recent location')->assertSee('Accuracy 18 m')
            ->assertSee($firstPoint)->assertDontSee('14.54321');
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $other->forceFill(['status' => \App\Enums\LogisticsProviderStatus::Approved])->save();
        $this->actingAs($other, 'logistics')->get($show)->assertNotFound()->assertDontSee($firstPoint);

        $this->travel(6)->minutes();
        $this->actingAs($owner, 'logistics')->get($show)->assertOk()
            ->assertSee('Stale location')->assertSee($firstPoint);
        $duty->change($f['replacement'], 'heartbeat', ['latitude' => 14.54321, 'longitude' => 121.54321, 'accuracy' => 10]);
        $this->transfer($f);
        $this->get($show)->assertOk()->assertSee('No location reported for this leg yet.')
            ->assertDontSee($firstPoint)->assertDontSee($replacementPoint);

        $this->travel(1)->seconds();
        $service = app(LinehaulService::class);
        foreach ([$f['parcel'], $f['secondParcel']] as $parcel) {
            $service->act($f['replacement'], $manifest, 'takeover-scan',
                ['offer_version' => 2, 'tracking_number' => $parcel->tracking_number], request());
        }
        $service->act($f['replacement'], $manifest, 'takeover-continue', ['offer_version' => 2], request());
        $this->get($show)->assertOk()->assertSee('No location reported for this leg yet.')
            ->assertDontSee($replacementPoint);

        $duty->change($f['replacement'], 'heartbeat', ['latitude' => 14.54321, 'longitude' => 121.54321, 'accuracy' => 10]);
        $this->get($show)->assertOk()->assertSee('Recent location')->assertSee($replacementPoint)->assertDontSee($firstPoint);
        $service->act($f['replacement'], $manifest, 'arrive', [], request());
        $this->get($show)->assertOk()->assertDontSee('Last trip location')->assertDontSee($replacementPoint);
    }

    public function test_trip_location_heartbeat_requires_the_current_continued_leg(): void
    {
        $f = $this->transferFixture();
        $manifest = $f['manifest'];
        $url = '/api/courier/duty/heartbeat';
        $point = ['latitude' => 14.12345, 'longitude' => 121.12345, 'accuracy' => 18];
        $leg = [...$point, 'trip_id' => $manifest->id, 'offer_version' => 1];
        $this->postJson($url, $leg)->assertUnauthorized();
        $this->transferApi($f['driver'])->postJson($url, [...$point, 'trip_id' => $manifest->id])
            ->assertUnprocessable()->assertJsonValidationErrors('offer_version');
        $this->postJson($url, $leg)->assertOk()->assertJsonPath('data.trip_location_sharing', true);
        $this->assertEquals($point, $f['driver']->fresh()->duty_location);
        $this->transfer($f);

        $this->postJson($url, [...$leg, 'latitude' => 14.3])
            ->assertUnprocessable()->assertJsonValidationErrors('trip_id');
        $this->assertEquals($point, $f['driver']->fresh()->duty_location);
        $replacement = [...$leg, 'offer_version' => 2];
        $this->transferApi($f['replacement'])->postJson($url, $replacement)
            ->assertUnprocessable()->assertJsonValidationErrors('trip_id');
        $service = app(LinehaulService::class);
        foreach ([$f['parcel'], $f['secondParcel']] as $parcel) {
            $service->act($f['replacement'], $manifest, 'takeover-scan',
                ['offer_version' => 2, 'tracking_number' => $parcel->tracking_number], request());
        }
        $service->act($f['replacement'], $manifest, 'takeover-continue', ['offer_version' => 2], request());
        $this->postJson($url, $leg)->assertUnprocessable()->assertJsonValidationErrors('trip_id');
        $this->postJson($url, $replacement)->assertOk();
        $service->act($f['replacement'], $manifest, 'arrive', [], request());
        $this->postJson($url, [...$replacement, 'latitude' => 14.3])
            ->assertUnprocessable()->assertJsonValidationErrors('trip_id');
        $this->assertEquals($point, $f['replacement']->fresh()->duty_location);
    }

    public function test_continue_rechecks_fresh_readiness_and_capacity_after_all_scans(): void
    {
        $f = $this->transferFixture(); $this->transfer($f);
        foreach ([$f['parcel'], $f['secondParcel']] as $parcel) {
            app(LinehaulService::class)->act($f['replacement'], $f['manifest'], 'takeover-scan',
                ['offer_version' => 2, 'tracking_number' => $parcel->tracking_number], request());
        }
        $driver = $f['replacement']->fresh();
        $driver->forceFill(['on_duty' => false])->save();
        try {
            app(LinehaulService::class)->act($driver, $f['manifest'], 'takeover-continue', ['offer_version' => 2], request());
            $this->fail('Off Duty continuation accepted.');
        } catch (ValidationException) { $this->assertNull($f['assignment']->fresh()->takeover_resumed_at); }
        $driver->forceFill(['on_duty' => true])->save();
        $f['replacementProfile']->forceFill(['capacity_kg' => '1.00'])->save();
        try {
            app(LinehaulService::class)->act($driver, $f['manifest'], 'takeover-continue', ['offer_version' => 2], request());
            $this->fail('Overweight continuation accepted.');
        } catch (ValidationException) {
            $this->assertNull($f['assignment']->fresh()->takeover_resumed_at);
            $this->assertNull($f['assignment']->fresh()->trip_pay_minor);
        }
    }
}
