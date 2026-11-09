<?php

namespace Tests\Feature;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulDutyCheck;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulDutyTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $this->withoutVite();
        $network = $this->operationalHandoffProvider('Duty Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Duty Courier', 'email' => 'duty@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        return [...$network, 'driver' => $driver];
    }

    private function payload(): array
    {
        return ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)];
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this->withToken($driver->createToken('duty', ['linehaul'])->plainTextToken);
    }

    public function test_duty_routes_require_the_real_role_and_cannot_change_another_driver(): void
    {
        ['driver' => $driver] = $this->fixture();
        $this->getJson('/api/courier/duty')->assertUnauthorized();
        $this->postJson('/api/courier/duty/on', $this->payload())->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->postJson('/api/courier/duty/on', $this->payload())->assertForbidden();
        $this->getJson('/api/courier/duty')->assertForbidden();
        $other = $driver->replicate();
        $other->forceFill(['email' => 'other-duty@example.com'])->save();
        $this->api($driver)->getJson('/api/courier/duty')->assertOk()->assertJsonPath('data.status', 'Off Duty');
        $this->postJson('/api/courier/duty/on', [...$this->payload(), 'driver_id' => $other->id, 'on_duty' => false])
            ->assertOk()->assertJsonPath('data.on_duty', true)->assertJsonPath('data.available', true)->assertJsonMissingPath('data.latitude');
        $this->assertFalse((bool) $other->fresh()->on_duty);
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/courier/duty/heartbeat', $this->payload())->assertForbidden();
    }

    public function test_strict_vehicle_setting_requires_an_approved_unexpired_profile_before_duty(): void
    {
        ['driver' => $driver] = $this->fixture();
        config(['linehaul.require_reviewed_vehicle' => true]);
        $this->api($driver)->postJson('/api/courier/duty/on', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('duty');
        $this->assertFalse((bool) $driver->fresh()->on_duty);

        $profile = new \App\Models\Logistics\LinehaulProfile;
        $profile->forceFill([
            'linehaul_driver_id' => $driver->id, 'plate_number' => 'GEO1234',
            'license_no' => 'N01-12-123456', 'license_expiry' => now('Asia/Manila')->addYear()->toDateString(),
            'license_codes' => ['C'], 'vehicle_type' => 'Van', 'capacity_kg' => '1000.00',
            'documents' => [], 'review_status' => 'pending', 'version' => 1,
        ])->save();
        $this->postJson('/api/courier/duty/on', $this->payload())->assertUnprocessable();
        $profile->forceFill(['review_status' => 'approved', 'license_expiry' => now('Asia/Manila')->subDay()->toDateString()])->save();
        $this->postJson('/api/courier/duty/on', $this->payload())->assertUnprocessable();
        $profile->forceFill(['license_expiry' => now('Asia/Manila')->addYear()->toDateString()])->save();
        $this->postJson('/api/courier/duty/on', $this->payload())->assertOk()->assertJsonPath('data.on_duty', true);
        $this->assertSame(1, LinehaulDutyCheck::count());
    }

    public function test_checklist_requires_every_answer_location_and_failed_item_note(): void
    {
        ['driver' => $driver, 'provider' => $provider] = $this->fixture();
        $this->api($driver);
        foreach (['latitude', 'longitude', 'accuracy'] as $field) {
            $input = $this->payload(); unset($input[$field]);
            $this->postJson('/api/courier/duty/on', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $input = $this->payload(); unset($input['checklist']['fuel']);
        $this->postJson('/api/courier/duty/on', $input)->assertUnprocessable()->assertJsonValidationErrors('checklist.fuel');
        $this->postJson('/api/courier/duty/on', [...$this->payload(), 'latitude' => 91])->assertUnprocessable();
        $this->postJson('/api/courier/duty/on', [...$this->payload(), 'accuracy' => 101])->assertUnprocessable();
        $input = $this->payload(); $input['checklist']['brakes'] = false;
        $this->postJson('/api/courier/duty/on', $input)->assertUnprocessable()->assertJsonValidationErrors('duty');
        $this->assertSame(0, LinehaulDutyCheck::count());
        $this->postJson('/api/courier/duty/on', [...$input, 'note' => 'Brake pedal feels soft.'])->assertOk()->assertJsonPath('data.on_duty', false)->assertJsonPath('data.available', false)->assertJsonPath('data.last_check_passed', false);
        $this->assertSame(1, LinehaulDutyCheck::count());
        $this->assertFalse(LinehaulDutyCheck::sole()->passed);
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierChecklistFailed::class)->count());
        $this->postJson('/api/courier/duty/heartbeat', $this->payload())->assertUnprocessable();
        $this->assertFalse((bool) $driver->fresh()->on_duty);
    }

    public function test_assignment_requires_fresh_readiness_and_active_trip_blocks_off_duty(): void
    {
        ['driver' => $driver, 'provider' => $provider, 'station' => $station, 'province' => $province] = $this->fixture();
        $manifest = app(ManifestService::class)->create($provider, $station->id, $province->id);
        $this->actingAs($provider, 'logistics')->postJson(route('logistics.manifests.courier', $manifest), ['driver_id' => $driver->id])->assertUnprocessable();
        $this->api($driver)->postJson('/api/courier/duty/on', $this->payload())->assertOk();
        $this->travel(5)->minutes();
        $this->getJson('/api/courier/duty')->assertOk()->assertJsonPath('data.status', 'Location unknown')->assertJsonPath('data.available', false);
        $this->actingAs($provider, 'logistics')->postJson(route('logistics.manifests.courier', $manifest), ['driver_id' => $driver->id])->assertUnprocessable();
        $this->api($driver)->postJson('/api/courier/duty/heartbeat', $this->payload())->assertOk()->assertJsonPath('data.available', true);
        app(LinehaulService::class)->assign($provider, $manifest, $driver->fresh());
        $this->postJson('/api/courier/duty/off')->assertUnprocessable()->assertJsonValidationErrors('duty');
        $this->assertTrue($driver->fresh()->on_duty);
        $this->travel(5)->minutes();
        $this->postJson('/api/courier/trips/'.$manifest->id.'/accept')->assertUnprocessable();
        $this->postJson('/api/courier/duty/heartbeat', $this->payload())->assertOk();
        $this->postJson('/api/courier/trips/'.$manifest->id.'/accept')->assertOk();
        $this->postJson('/api/courier/duty/on', $this->payload())->assertUnprocessable();
        $this->assertSame(1, LinehaulDutyCheck::count());
    }

    public function test_off_duty_persists_and_logistics_sees_only_own_company_readiness(): void
    {
        ['driver' => $driver, 'provider' => $provider, 'station' => $station, 'province' => $province] = $this->fixture();
        $this->api($driver)->postJson('/api/courier/duty/on', $this->payload())->assertOk();
        $this->postJson('/api/courier/duty/off')->assertOk()->assertJsonPath('data.status', 'Off Duty');
        $this->getJson('/api/courier/duty')->assertOk()->assertJsonPath('data.on_duty', false);
        $this->assertNull($driver->fresh()->duty_location);
        $this->assertSame(2, LinehaulDutyCheck::count());
        $manifest = app(ManifestService::class)->create($provider, $station->id, $province->id);
        $otherProvider = \App\Models\Logistics\LogisticsProvider::factory()->create();
        $foreign = $driver->replicate();
        $foreign->forceFill(['name' => 'PRIVATE FOREIGN COURIER', 'email' => 'private@example.com', 'logistics_provider_id' => $otherProvider->id])->save();
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics')->get(route('logistics.manifests.show', $manifest))
            ->assertOk()->assertSee('Courier readiness')->assertSee('Duty Courier')->assertSee('Off Duty')->assertDontSee('PRIVATE FOREIGN COURIER');
        $this->postJson(route('logistics.manifests.courier', $manifest), ['driver_id' => $driver->id])->assertUnprocessable();
    }

    public function test_duty_actions_share_the_authenticated_throttle(): void
    {
        ['driver' => $driver] = $this->fixture();
        $this->api($driver);
        for ($request = 0; $request < 60; $request++) {
            $this->getJson('/api/courier/duty')->assertOk();
        }
        $this->postJson('/api/courier/duty/on', $this->payload())->assertTooManyRequests();
        $this->assertFalse((bool) $driver->fresh()->on_duty);
    }
}
