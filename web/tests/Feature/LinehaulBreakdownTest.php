<?php

namespace Tests\Feature;

use App\Models\Logistics\LinehaulBreakdown;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDelay;
use App\Models\Logistics\ManifestAuditLog;
use App\Services\Logistics\LinehaulBreakdownService;
use App\Services\Logistics\LinehaulService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsLinehaulBreakdowns;
use Tests\TestCase;

class LinehaulBreakdownTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsLinehaulBreakdowns;

    private function payload(): array
    {
        return ['offer_version' => 1, 'reason' => 'Engine stopped', 'new_eta' => now()->addHour()->toIso8601String(),
            'photo' => UploadedFile::fake()->image('breakdown.png', 1600, 800)];
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('breakdown-test', ['linehaul'])->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url($manifest): string
    {
        return '/api/courier/trips/'.$manifest->id.'/breakdown';
    }

    public function test_owner_role_validation_and_shared_throttle_protect_the_new_route(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->postJson($this->url($manifest), [])->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->postJson($this->url($manifest), [])->assertForbidden();
        $other = $driver->replicate()->forceFill(['email' => 'other-breakdown@example.com']);
        $other->save();
        $this->api($other)->post($this->url($manifest), $this->payload())->assertForbidden();
        $this->api($driver);
        $this->post($this->url($manifest), [...$this->payload(), 'photo' => null])->assertUnprocessable()->assertJsonValidationErrors('photo');
        foreach (['photo_path', 'photo_hash', 'linehaul_trip_delay_id', 'type', 'driver_id', 'status'] as $field) {
            $this->post($this->url($manifest), [...$this->payload(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->post($this->url($manifest), [...$this->payload(), 'reason' => ' '])->assertUnprocessable();
        $this->post($this->url($manifest), [...$this->payload(), 'new_eta' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->post($this->url($manifest), [...$this->payload(), 'offer_version' => 2])->assertUnprocessable();
        $this->assertSame(0, LinehaulBreakdown::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/courier/trips/{manifest}/breakdown');
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
        for ($index = 0; $index < 61; $index++) {
            $response = $this->postJson($this->url($manifest), []);
        }
        $response->assertTooManyRequests();
    }

    public function test_photo_is_required_validated_by_content_bounded_and_reencoded_privately(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment, 'parcel' => $parcel, 'provider' => $provider] = $this->fixture();
        $before = [$manifest->fresh()->getRawOriginal(), $assignment->fresh()->getRawOriginal(), $parcel->fresh()->getRawOriginal()];
        $this->api($driver);
        $oversized = UploadedFile::fake()->image('oversized.png', 10, 10);
        file_put_contents($oversized->getRealPath(), str_repeat('x', 5121 * 1024), FILE_APPEND);
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<svg>not a photo</svg>'),
            $oversized, UploadedFile::fake()->create('huge.jpg', 5121, 'image/jpeg'), UploadedFile::fake()->image('wide.png', 4097, 1)] as $bad) {
            $this->post($this->url($manifest), [...$this->payload(), 'photo' => $bad])->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $payload = $this->payload();
        file_put_contents($payload['photo']->getRealPath(), 'PRIVATE-EXIF-GPS-MARKER', FILE_APPEND);
        $response = $this->post($this->url($manifest), $payload)->assertOk()->assertJsonPath('data.type', 'vehicle_breakdown')->assertJsonPath('data.photo_received', true);
        $response->assertJsonPath('data.delay.reason', 'Vehicle breakdown: Engine stopped');
        $report = LinehaulBreakdown::sole();
        $stored = Storage::disk('local')->get($report->photo_path);
        $this->assertSame('image/jpeg', getimagesizefromstring($stored)['mime']);
        $this->assertSame(1280, getimagesizefromstring($stored)[0]);
        $this->assertStringNotContainsString('PRIVATE-EXIF-GPS-MARKER', $stored);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringNotContainsString($report->photo_path, $response->getContent());
        $this->assertStringNotContainsString($report->photo_hash, $response->getContent());
        $this->assertSame($before, [$manifest->fresh()->getRawOriginal(), $assignment->fresh()->getRawOriginal(), $parcel->fresh()->getRawOriginal()]);
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_breakdown')->count());
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
    }

    public function test_identical_retry_is_stable_but_cannot_replace_evidence_or_attach_after_arrival(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $data = $this->payload();
        $this->api($driver)->post($this->url($manifest), $data)->assertOk();
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.can_report_breakdown', true);
        $id = LinehaulBreakdown::sole()->id;
        $this->post($this->url($manifest), [...$data, 'new_eta' => '2026-11-08T13:00:00+08:00'])->assertOk()->assertJsonPath('data.id', $id);
        $this->post($this->url($manifest), [...$data, 'photo' => UploadedFile::fake()->image('other.jpg', 10, 10)])->assertUnprocessable();
        app(LinehaulService::class)->act($driver, $manifest, 'arrive', [], request());
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.can_report_breakdown', false);
        $this->travel(2)->hours();
        $this->post($this->url($manifest), $data)->assertOk()->assertJsonPath('data.id', $id);
        $this->post($this->url($manifest), [...$this->payload(), 'reason' => 'New issue'])->assertUnprocessable();
        $this->assertSame(1, LinehaulBreakdown::count());
        $this->assertSame(1, LinehaulTripDelay::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
    }

    public function test_draft_reports_are_refused_and_failed_audit_rolls_back_delay_notification_and_photo(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture(false);
        $this->api($driver)->post($this->url($manifest), $this->payload())->assertUnprocessable();
        app(LinehaulService::class)->act($driver, $manifest, 'depart', ['seal_number' => 'BH-0000771'], request());
        ManifestAuditLog::creating(function ($audit): void {
            if ($audit->action === 'courier_breakdown') {
                throw new \RuntimeException('Simulated breakdown audit failure');
            }
        });
        try {
            app(LinehaulBreakdownService::class)->report($driver, $manifest, $this->payload(), request());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated breakdown audit failure', $error->getMessage());
        } finally {
            ManifestAuditLog::flushEventListeners();
        }
        $this->assertSame(0, LinehaulTripDelay::count());
        $this->assertSame(0, LinehaulBreakdown::count());
        $this->assertSame(0, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDelayed::class)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_evidence_is_append_only_and_setup_absence_fails_closed(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->api($driver)->post($this->url($manifest), $this->payload())->assertOk();
        $report = LinehaulBreakdown::sole();
        foreach ([fn () => $report->forceFill(['photo_path' => 'forged'])->save(), fn () => $report->delete(),
            fn () => LinehaulBreakdown::query()->update(['photo_hash' => 'forged']), fn () => LinehaulBreakdown::query()->delete()] as $change) {
            try {
                $change();
                $this->fail('Evidence must be append-only');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        \Illuminate\Support\Facades\Schema::drop('linehaul_breakdown_reports');
        $cache = new \ReflectionProperty(\App\Support\RuntimeSchema::class, 'tables');
        $previous = $cache->getValue();
        $cache->setValue(null, []);
        try {
            $this->post($this->url($manifest), $this->payload())->assertUnprocessable();
            $this->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.can_report_breakdown', false);
            $this->actingAs($driver->provider, 'logistics')->getJson(route('logistics.courier-breakdowns.photo', $report))->assertNotFound();
        } finally {
            $cache->setValue(null, $previous);
        }
    }

    public function test_logistics_photo_is_private_company_scoped_validated_and_throttled(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $this->api($driver)->post($this->url($manifest), $this->payload())->assertOk();
        $report = LinehaulBreakdown::sole();
        $url = route('logistics.courier-breakdowns.photo', $report);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $other->forceFill(['status' => \App\Enums\LogisticsProviderStatus::Approved, 'archived_at' => null])->save();
        $this->actingAs($other, 'logistics')->getJson($url)->assertForbidden();
        $this->actingAs($provider, 'logistics')->getJson($url.'?photo_path=forged')->assertUnprocessable();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertDownload('courier-document.jpg');
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()->assertSee('Courier breakdown reports')->assertSee('Download breakdown photo')->assertDontSee($report->photo_path);
        for ($index = 0; $index < 21; $index++) {
            $response = $this->getJson($url);
        }
        $response->assertTooManyRequests();
    }
}
