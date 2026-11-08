<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDelay;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulDelayService;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulDelayTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $this->withoutVite();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-08T04:00:00Z'));
        $network = $this->operationalHandoffProvider('Delay Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Delay Courier', 'email' => 'delay@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        app(LinehaulDutyService::class)->change($driver, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)]);
        $parcel = SellerOrder::factory()->create(['tracking_number' => 'DELAY-001']);
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $network['station']->id,
            'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id, 'custody_provider_id' => null, 'handover_at' => now()])->save();
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);
        app(ManifestService::class)->addParcel($manifest, $parcel);
        $service = app(LinehaulService::class);
        $assignment = $service->assign($network['provider'], $manifest, $driver);
        $service->act($driver, $manifest, 'accept', [], request());
        $service->act($driver, $manifest, 'scan', ['tracking_number' => $parcel->tracking_number, 'method' => 'manual'], request());
        $service->act($driver, $manifest, 'depart', ['seal_number' => 'BH-0000771'], request());

        return [...$network, ...compact('driver', 'manifest', 'assignment', 'parcel')];
    }

    private function payload(): array
    {
        return ['offer_version' => 1, 'reason' => 'Heavy traffic', 'new_eta' => now()->addHour()->toIso8601String()];
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('delay-test', ['linehaul'])->plainTextToken);
    }

    private function url($manifest): string
    {
        return '/api/courier/trips/'.$manifest->id.'/delay';
    }

    public function test_report_requires_real_owner_role_reason_explicit_timezone_future_eta_and_current_offer(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->postJson($this->url($manifest), $this->payload())->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->postJson($this->url($manifest), $this->payload())->assertForbidden();
        $other = $driver->replicate()->forceFill(['email' => 'other-delay@example.com']); $other->save();
        $this->api($other)->postJson($this->url($manifest), $this->payload())->assertForbidden();
        $this->api($driver);
        foreach ([['reason' => ' '], ['reason' => str_repeat('x', 501)], ['new_eta' => now()->subMinute()->toIso8601String()],
            ['new_eta' => now()->toIso8601String()], ['new_eta' => '2026-11-08 05:00:00'], ['new_eta' => '2026-02-30T05:00:00Z'],
            ['new_eta' => '2027-02-30T05:00:00Z'], ['new_eta' => '2026-11-08T05:00:00+99:00'], ['offer_version' => 2]] as $bad) {
            $this->postJson($this->url($manifest), [...$this->payload(), ...$bad])->assertUnprocessable();
        }
        foreach (['driver_id', 'status', 'logistics_provider_id', 'linehaul_assignment_id', 'fingerprint', 'created_at'] as $field) {
            $this->postJson($this->url($manifest), [...$this->payload(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, LinehaulTripDelay::count());
        $this->postJson($this->url($manifest), [...$this->payload(), 'new_eta' => now()->addMinute()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'In Transit')->assertJsonPath('data.delay.new_eta', '2026-11-08T04:01:00+00:00');
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->api($driver)->postJson($this->url($manifest), $this->payload())->assertForbidden();
    }

    public function test_report_preserves_trip_custody_and_parcels_and_notifies_only_owning_logistics(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment, 'parcel' => $parcel, 'provider' => $provider] = $this->fixture();
        $before = [$manifest->fresh()->getAttributes(), $assignment->fresh()->getAttributes(), $parcel->fresh()->getAttributes()];
        $this->api($driver)->postJson($this->url($manifest), $this->payload())->assertOk()
            ->assertJsonPath('data.status', 'In Transit')->assertJsonPath('data.can_report_delay', true)
            ->assertJsonPath('data.delay.reason', 'Heavy traffic')->assertJsonPath('data.delay.new_eta', '2026-11-08T05:00:00+00:00')
            ->assertJsonMissingPath('data.delay.fingerprint')->assertJsonMissingPath('data.delay.linehaul_driver_id');
        $this->assertSame($before, [$manifest->fresh()->getAttributes(), $assignment->fresh()->getAttributes(), $parcel->fresh()->getAttributes()]);
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
        $this->assertDatabaseCount('linehaul_trip_delays', 1);
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_delay')->count());
        $notice = $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->first()->data;
        $this->assertSame('/logistics/manifests/'.$manifest->id, $notice['url']);
        $this->assertSame('2026-11-08T05:00:00+00:00', $notice['new_eta']);
        $this->assertStringContainsString('05:00 UTC', $notice['message']);
        $this->assertStringContainsString('Heavy traffic', $notice['message']);
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics')->get(route('logistics.manifests.show', $manifest))->assertOk()->assertSee('Delayed')->assertSee('Heavy traffic')->assertSee('Courier-reported ETA');
        $this->get(route('logistics.manifests.index'))->assertOk()->assertSee('Delayed')->assertSee('Courier-reported ETA');
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $this->actingAs($other, 'logistics')->get(route('logistics.manifests.show', $manifest))->assertNotFound();
        $this->get(route('logistics.manifests.index'))->assertOk()->assertDontSee('Heavy traffic')->assertDontSee('Courier-reported ETA');
    }

    public function test_duplicate_offsets_retries_and_old_reports_do_not_duplicate_or_overwrite_latest_eta(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $first = $this->payload();
        $this->api($driver)->postJson($this->url($manifest), $first)->assertOk();
        $this->postJson($this->url($manifest), [...$first, 'reason' => ' Heavy traffic ', 'new_eta' => '2026-11-08T13:00:00.000+08:00'])->assertOk();
        $this->assertSame(1, LinehaulTripDelay::count());
        $this->postJson($this->url($manifest), [...$first, 'reason' => 'Road closure', 'new_eta' => now()->addHours(2)->toIso8601String()])->assertOk()->assertJsonPath('data.delay.reason', 'Road closure');
        $this->postJson($this->url($manifest), $first)->assertOk()->assertJsonPath('data.delay.reason', 'Road closure')->assertJsonPath('data.delay.new_eta', '2026-11-08T06:00:00+00:00');
        $this->travel(3)->hours();
        $this->postJson($this->url($manifest), $first)->assertOk()->assertJsonPath('data.delay.reason', 'Road closure');
        $this->assertSame(2, LinehaulTripDelay::count());
        $this->assertSame(2, ManifestAuditLog::where('action', 'courier_delay')->count());
        $this->assertSame(2, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
    }

    public function test_new_reports_only_while_courier_in_transit_but_existing_replay_survives_arrival(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment] = $this->fixture();
        $first = $this->payload();
        $this->api($driver)->postJson($this->url($manifest), $first)->assertOk();
        $new = [...$first, 'reason' => 'New reason'];
        $manifest->refresh()->forceFill(['status' => ManifestStatus::Draft])->save();
        $this->postJson($this->url($manifest), $new)->assertUnprocessable();
        $manifest->forceFill(['status' => ManifestStatus::Dispatched, 'dispatched_by_type' => 'logistics'])->save();
        $this->postJson($this->url($manifest), $new)->assertUnprocessable();
        $manifest->forceFill(['dispatched_by_type' => 'linehaul', 'dispatched_by_id' => $driver->id])->save();
        app(LinehaulService::class)->act($driver, $manifest, 'arrive', [], request());
        $this->postJson($this->url($manifest), $new)->assertUnprocessable();
        $this->postJson($this->url($manifest), $first)->assertOk()->assertJsonPath('data.status', 'Arrived')->assertJsonPath('data.can_report_delay', false);
        $manifest->forceFill(['status' => ManifestStatus::Received])->save();
        $this->postJson($this->url($manifest), $new)->assertUnprocessable();
        $this->postJson($this->url($manifest), $first)->assertOk()->assertJsonPath('data.status', 'Completed');
        $this->assertSame(1, LinehaulTripDelay::count());
    }

    public function test_delay_metadata_is_bound_to_the_current_courier_offer_epoch(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment] = $this->fixture();
        $this->api($driver)->postJson($this->url($manifest), $this->payload())->assertOk();
        $assignment->refresh()->forceFill(['offer_version' => 2])->save();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.delay', null);
        $this->postJson($this->url($manifest), $this->payload())->assertUnprocessable();
        $this->assertSame(1, LinehaulTripDelay::count());
        $report = LinehaulTripDelay::sole();
        $assignment->setRelation('manifest', $manifest->fresh());
        foreach (['linehaul_driver_id', 'logistics_provider_id'] as $field) {
            $unbound = $report->replicate()->forceFill(['offer_version' => 2, $field => 999999]);
            $assignment->setRelation('latestDelay', $unbound);
            $this->assertNull(LinehaulDelayService::latest($assignment));
        }
    }

    public function test_report_audit_failure_rolls_back_history_and_notification_and_history_is_immutable(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $dispatcher = ManifestAuditLog::getEventDispatcher(); ManifestAuditLog::setEventDispatcher(clone $dispatcher);
        ManifestAuditLog::creating(function ($audit): void {
            if ($audit->action === 'courier_delay') {
                throw new \RuntimeException('Simulated delay audit failure');
            }
        });
        try {
            app(LinehaulDelayService::class)->report($driver, $manifest, $this->payload(), request());
            $this->fail('Audit failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated delay audit failure', $exception->getMessage());
        } finally {
            ManifestAuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, LinehaulTripDelay::count());
        $this->assertSame(0, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
        $this->api($driver)->postJson($this->url($manifest), $this->payload())->assertOk();
        foreach (['save', 'delete', 'bulk-update', 'bulk-delete'] as $operation) {
            $report = LinehaulTripDelay::sole();
            try {
                match ($operation) {
                    'save' => $report->forceFill(['reason' => 'Overwrite'])->save(), 'delete' => $report->delete(),
                    'bulk-update' => LinehaulTripDelay::whereKey($report->id)->update(['reason' => 'Overwrite']),
                    'bulk-delete' => LinehaulTripDelay::whereKey($report->id)->delete(),
                };
                $this->fail('Delay history was changed.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be', $exception->getMessage());
            }
        }
        $this->assertSame('Heavy traffic', LinehaulTripDelay::sole()->reason);
    }

    public function test_delay_route_shares_the_existing_authenticated_rate_limit(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->api($driver);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson($this->url($manifest), [])->assertUnprocessable();
        }
        $this->postJson($this->url($manifest), $this->payload())->assertStatus(429);

        $this->assertSame(0, LinehaulTripDelay::count());
    }

    public function test_trip_details_use_only_recorded_parcel_weights_and_actual_times_without_writing_records(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment, 'parcel' => $parcel] = $this->fixture();
        $parcel->forceFill(['parcel_weight_grams' => 3600])->save();
        $before = [$manifest->fresh()->getAttributes(), $assignment->fresh()->getAttributes(), $parcel->fresh()->getAttributes()];
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()
            ->assertJsonPath('data.0.parcel_count', 1)->assertJsonPath('data.0.recorded_weight_grams', 3600)
            ->assertJsonPath('data.0.accepted_at', $assignment->fresh()->accepted_at->toIso8601String())
            ->assertJsonPath('data.0.departed_at', $manifest->fresh()->dispatched_at->toIso8601String())
            ->assertJsonMissingPath('data.0.scheduled_departure')->assertJsonMissingPath('data.0.pay');
        $this->assertSame($before, [$manifest->fresh()->getAttributes(), $assignment->fresh()->getAttributes(), $parcel->fresh()->getAttributes()]);
        foreach ([null, 0] as $missing) {
            $parcel->forceFill(['parcel_weight_grams' => $missing])->save();
            $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.recorded_weight_grams', null);
        }
        $parcel->forceFill(['parcel_weight_grams' => 1500])->save();
        $extra = SellerOrder::factory()->create(['tracking_number' => 'DETAIL-EXTRA', 'parcel_weight_grams' => 2100]);
        $item = $manifest->items()->create(['seller_order_id' => $extra->id, 'tracking_number' => $extra->tracking_number]);
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.parcel_count', 2)->assertJsonPath('data.0.recorded_weight_grams', 3600);
        $item->forceFill(['released_at' => now()])->save();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.parcel_count', 1)->assertJsonPath('data.0.recorded_weight_grams', 1500);
        $item->forceFill(['received_at' => now()])->save();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.parcel_count', 2)->assertJsonPath('data.0.recorded_weight_grams', 3600);
        $extra->forceFill(['parcel_weight_grams' => null])->save();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.recorded_weight_grams', null);
    }
}
