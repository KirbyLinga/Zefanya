<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Models\Logistics\ManifestAuditLog;
use App\Services\Logistics\LinehaulScheduleService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulScheduleTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $this->withoutVite();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-08T04:00:00Z'));
        $network = $this->operationalHandoffProvider('Schedule Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);

        return [...$network, 'manifest' => $manifest];
    }

    private function payload(int $version = 0, ?string $time = '2026-11-08T13:00'): array
    {
        return ['schedule_version' => $version, 'scheduled_departure' => $time];
    }

    private function url($manifest): string
    {
        return '/logistics/manifests/'.$manifest->id.'/schedule';
    }

    public function test_schedule_requires_logistics_session_and_owning_approved_company(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $this->postJson($this->url($manifest), $this->payload())->assertUnauthorized();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $this->actingAs($other, 'logistics')->postJson($this->url($manifest), $this->payload())->assertForbidden();
        $this->assertNull($manifest->fresh()->scheduled_departure_at);
        $this->actingAs($provider, 'logistics')->postJson($this->url($manifest), $this->payload())->assertOk();
    }

    public function test_schedule_validates_and_records_explicit_philippine_time_without_departing(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $this->actingAs($provider, 'logistics');
        foreach (['2026-11-08T11:59', '2026-11-08T12:00', '2027-02-30T13:00', 'tomorrow', '2026-11-08T13:00Z'] as $invalid) {
            $this->postJson($this->url($manifest), $this->payload(0, $invalid))->assertUnprocessable();
        }
        $this->postJson($this->url($manifest), ['schedule_version' => 0])->assertUnprocessable();
        $this->postJson($this->url($manifest), [...$this->payload(), 'status' => 'DISPATCHED'])->assertUnprocessable();
        $before = $manifest->fresh()->getAttributes();
        $this->postJson($this->url($manifest), $this->payload())->assertOk()->assertJsonPath('data.schedule_version', 1);
        $saved = $manifest->fresh();
        $this->assertSame('2026-11-08T05:00:00+00:00', $saved->scheduled_departure_at->copy()->utc()->toIso8601String());
        $this->assertSame(ManifestStatus::Draft, $saved->status);
        $this->assertNull($saved->dispatched_at);
        foreach ($before as $key => $value) {
            if (! in_array($key, ['scheduled_departure_at', 'schedule_version', 'updated_at'], true)) {
                $this->assertSame($value, $saved->getAttributes()[$key]);
            }
        }
        $audit = ManifestAuditLog::where('action', 'courier_schedule')->sole();
        $this->assertSame((int) $provider->id, (int) $audit->actor_id);
        $this->assertNull($audit->schedule_change['before']);
        $this->assertSame(1, $audit->schedule_change['version']);
        $this->assertSame($saved->scheduled_departure_at->toIso8601String(), $audit->schedule_change['after']);
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()->assertSee('Planned departure')->assertSee('Philippine time')->assertSee('Recent schedule changes');
    }

    public function test_schedule_stale_edits_are_refused_and_saved_retry_does_not_duplicate_audit(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $this->actingAs($provider, 'logistics');
        $this->postJson($this->url($manifest), $this->payload())->assertOk();
        $this->travel(2)->hours();
        $this->postJson($this->url($manifest), $this->payload())->assertOk();
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_schedule')->count());
        $this->postJson($this->url($manifest), $this->payload(0, '2026-11-08T16:00'))->assertUnprocessable();
        $this->postJson($this->url($manifest), $this->payload(1, '2026-11-08T16:00'))->assertOk()->assertJsonPath('data.schedule_version', 2);
        $this->postJson($this->url($manifest), $this->payload())->assertUnprocessable();
        $this->assertSame(2, ManifestAuditLog::where('action', 'courier_schedule')->count());
    }

    public function test_schedule_clear_and_phase_guards_preserve_actual_departure(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $this->actingAs($provider, 'logistics');
        $this->postJson($this->url($manifest), $this->payload())->assertOk();
        $this->postJson($this->url($manifest), $this->payload(1, null))->assertOk()->assertJsonPath('data.scheduled_departure_at', null);
        $manifest->refresh()->forceFill(['direction' => 'return'])->save();
        $this->postJson($this->url($manifest), $this->payload(2))->assertUnprocessable();
        $manifest->forceFill(['direction' => 'forward', 'status' => ManifestStatus::Dispatched, 'dispatched_at' => now()])->save();
        $before = $manifest->fresh()->getAttributes();
        $this->postJson($this->url($manifest), $this->payload(2))->assertUnprocessable();
        $this->assertSame($before, $manifest->fresh()->getAttributes());
    }

    public function test_schedule_audit_failure_rolls_back_and_shared_throttle_applies(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $dispatcher = ManifestAuditLog::getEventDispatcher();
        ManifestAuditLog::setEventDispatcher(clone $dispatcher);
        ManifestAuditLog::creating(function ($audit): void {
            if ($audit->action === 'courier_schedule') throw new \RuntimeException('Schedule audit unavailable');
        });
        try {
            app(LinehaulScheduleService::class)->save($provider, $manifest, $this->payload(), request());
            $this->fail('Audit failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Schedule audit unavailable', $exception->getMessage());
        } finally {
            ManifestAuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertNull($manifest->fresh()->scheduled_departure_at);
        $this->assertSame(0, $manifest->fresh()->schedule_version);
        $this->actingAs($provider, 'logistics');
        for ($i = 0; $i < 20; $i++) {
            $this->postJson($this->url($manifest), [])->assertUnprocessable();
        }
        $this->postJson($this->url($manifest), $this->payload())->assertStatus(429);
    }

    public function test_courier_trip_resource_shows_plan_separately_from_actual_departure(): void
    {
        ['provider' => $provider, 'manifest' => $manifest] = $this->fixture();
        $saved = app(LinehaulScheduleService::class)->save($provider, $manifest, $this->payload(), request());
        $assignment = new \App\Models\Logistics\LinehaulAssignment;
        $assignment->forceFill(['manifest_id' => $manifest->id, 'linehaul_driver_id' => 1]);
        $assignment->setRelation('manifest', $saved->load(['items', 'originHub', 'destinationHub']));
        $resource = (new \App\Http\Resources\Mobile\LinehaulTripResource($assignment))->resolve(request());
        $this->assertSame($saved->scheduled_departure_at->toIso8601String(), $resource['scheduled_departure_at']);
        $this->assertNull($resource['departed_at']);
        $this->assertSame('Assigned', $resource['status']);
    }
}
