<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDecline;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulDeclineService;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulDeclineTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $this->withoutVite();
        $network = $this->operationalHandoffProvider('Decline Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'First Courier', 'email' => 'decline@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        $this->ready($driver);
        $parcel = SellerOrder::factory()->create(['tracking_number' => 'DECLINE-ORIGINAL']);
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $network['station']->id,
            'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id, 'handover_at' => now()])->save();
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);
        app(ManifestService::class)->addParcel($manifest, $parcel);
        $assignment = app(LinehaulService::class)->assign($network['provider'], $manifest, $driver);

        return [...$network, ...compact('driver', 'manifest', 'parcel', 'assignment')];
    }

    private function ready(LinehaulDriver $driver): void
    {
        app(LinehaulDutyService::class)->change($driver, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)]);
    }

    private function another(LinehaulDriver $driver): LinehaulDriver
    {
        $copy = $driver->replicate()->forceFill(['name' => 'Second Courier', 'email' => 'second-decline@example.com']);
        $copy->save();
        $this->ready($copy);

        return $copy->fresh();
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('decline-test', ['linehaul'])->plainTextToken);
    }

    private function url($manifest): string
    {
        return '/api/courier/trips/'.$manifest->id.'/decline';
    }

    private function body(): array
    {
        return ['offer_version' => 1, 'reason' => 'Vehicle problem'];
    }

    public function test_decline_requires_real_owner_role_reason_and_offer_version(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->postJson($this->url($manifest), $this->body())->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->postJson($this->url($manifest), $this->body())->assertForbidden();
        $other = $this->another($driver);
        $this->assertTrue((new \App\Policies\LinehaulPolicy)->decline($driver, $manifest));
        $this->assertFalse((new \App\Policies\LinehaulPolicy)->decline($other, $manifest));
        $this->api($other)->postJson($this->url($manifest), $this->body())->assertForbidden();
        $other->forceFill(['logistics_provider_id' => \App\Models\Logistics\LogisticsProvider::factory()->create()->id])->save();
        $this->api($other)->postJson($this->url($manifest), $this->body())->assertForbidden();
        $this->api($driver);
        foreach ([[], ['offer_version' => 1], ['reason' => ' ', 'offer_version' => 1], ['reason' => str_repeat('x', 501), 'offer_version' => 1], ['offer_version' => 0, 'reason' => 'Reason']] as $bad) {
            $this->postJson($this->url($manifest), $bad)->assertUnprocessable();
        }
        foreach (['driver_id', 'status', 'logistics_provider_id', 'manifest_id', 'linehaul_driver_id', 'snapshot', 'created_at', 'declined_at'] as $field) {
            $this->postJson($this->url($manifest), [...$this->body(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->url($manifest), [...$this->body(), 'offer_version' => 2])->assertUnprocessable();
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->api($driver)->postJson($this->url($manifest), $this->body())->assertForbidden();
        $this->assertSame(0, LinehaulTripDecline::count());
        $this->assertNull(LinehaulAssignment::sole()->declined_at);
    }

    public function test_decline_unassigns_preserves_native_data_notifies_own_company_and_replays_once(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'parcel' => $parcel, 'provider' => $provider] = $this->fixture();
        $before = $parcel->fresh()->getAttributes();
        $this->api($driver)->getJson('/api/courier/trips')->assertJsonPath('data.0.can_decline', true)->assertJsonPath('data.0.offer_version', 1);
        $this->postJson($this->url($manifest), $this->body())->assertOk()->assertJsonPath('data.status', 'Declined')
            ->assertJsonPath('data.decline_reason', 'Vehicle problem')->assertJsonPath('data.parcel_count', 1)->assertJsonPath('data.items', []);
        $this->assertFalse(LinehaulDutyService::busy($driver));
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $this->assertSame('DRAFT', $manifest->fresh()->status->value);
        $this->assertSame(1, $manifest->items()->whereNull('released_at')->count());
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDeclined::class)->count());
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'courier_decline')->count());
        $this->postJson($this->url($manifest), $this->body())->assertOk();
        $this->postJson($this->url($manifest), [...$this->body(), 'reason' => 'Overwrite'])->assertUnprocessable();
        $this->assertSame(1, LinehaulTripDecline::count());
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDeclined::class)->count());
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Declined');
        foreach (['accept', 'scan', 'depart', 'arrive', 'exception-note'] as $action) {
            $this->postJson('/api/courier/trips/'.$manifest->id.'/'.$action, [])->assertForbidden();
        }
        $this->postJson('/api/courier/duty/off')->assertOk()->assertJsonPath('data.on_duty', false);
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics')->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('Courier decline history')->assertSee('Vehicle problem')->assertDontSee('Courier: First Courier');
        $this->get(route('logistics.manifests.index'))->assertOk()->assertSee('Courier unassigned')->assertSee('Vehicle problem');
    }

    public function test_accept_or_loading_wins_over_decline_and_decline_wins_over_stale_accept(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment] = $this->fixture();
        $this->api($driver)->postJson('/api/courier/trips/'.$manifest->id.'/accept')->assertOk();
        $this->postJson($this->url($manifest), $this->body())->assertUnprocessable();
        $assignment->refresh()->forceFill(['accepted_at' => null, 'loading_tokens' => ['1' => 'stale-token']])->save();
        $this->postJson($this->url($manifest), $this->body())->assertUnprocessable();
        $assignment->forceFill(['loading_tokens' => null, 'seal_number' => 'BH-0000001'])->save();
        $this->postJson($this->url($manifest), $this->body())->assertUnprocessable();
        $assignment->forceFill(['seal_number' => null])->save();
        $manifest->forceFill(['status' => \App\Enums\ManifestStatus::Dispatched])->save();
        $this->postJson($this->url($manifest), $this->body())->assertUnprocessable();
        $manifest->forceFill(['status' => \App\Enums\ManifestStatus::Draft])->save();
        $this->postJson($this->url($manifest), $this->body())->assertOk();
        $this->postJson('/api/courier/trips/'.$manifest->id.'/accept')->assertForbidden();
        $this->assertNull($assignment->fresh()->accepted_at);
    }

    public function test_reassignment_has_one_new_owner_and_old_replay_returns_only_frozen_history(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'assignment' => $assignment] = $this->fixture();
        $other = $this->another($driver);
        $this->api($driver)->postJson($this->url($manifest), $this->body())->assertOk();
        try {
            app(LinehaulService::class)->assign($provider, $manifest, $driver);
            $this->fail('Declined Courier was reoffered the trip.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('declined', $exception->getMessage());
        }
        $next = app(LinehaulService::class)->assign($provider, $manifest, $other);
        $this->assertSame($assignment->id, $next->id);
        $this->assertSame(2, $next->offer_version);
        $this->assertSame($other->id, $next->linehaul_driver_id);
        $this->assertNull($next->declined_at);
        $extra = SellerOrder::factory()->create(['tracking_number' => 'PRIVATE-AFTER-DECLINE']);
        $extra->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $manifest->origin_hub_id,
            'custody_hub_id' => $manifest->origin_hub_id, 'carrier_provider_id' => $provider->id, 'handover_at' => now()])->save();
        app(ManifestService::class)->addParcel($manifest->fresh(), $extra);
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.parcel_count', 1)->assertJsonPath('data.0.items', [])->assertDontSee('PRIVATE-AFTER-DECLINE');
        $this->postJson($this->url($manifest), $this->body())->assertOk()->assertJsonPath('data.offer_version', 1)->assertJsonPath('data.parcel_count', 1);
        $this->postJson($this->url($manifest), [...$this->body(), 'offer_version' => 2])->assertUnprocessable();
        $this->api($other)->postJson($this->url($manifest), $this->body())->assertUnprocessable();
        $this->postJson('/api/courier/trips/'.$manifest->id.'/accept')->assertOk();
        $this->postJson($this->url($manifest), [...$this->body(), 'offer_version' => 2])->assertUnprocessable();
        $this->assertSame($other->id, LinehaulAssignment::sole()->linehaul_driver_id);
        $this->assertSame(1, LinehaulTripDecline::count());
    }

    public function test_history_survives_native_draft_deletion_and_cannot_be_edited(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $this->api($driver)->postJson($this->url($manifest), $this->body())->assertOk();
        app(ManifestService::class)->deleteManifest($manifest, $provider);
        $this->assertDatabaseMissing('manifests', ['id' => $manifest->id]);
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Declined');
        $history = LinehaulTripDecline::sole();
        foreach (['save', 'delete', 'bulk-update', 'bulk-delete'] as $operation) {
            try {
                match ($operation) {
                    'save' => $history->forceFill(['reason' => 'Overwrite'])->save(),
                    'delete' => $history->delete(),
                    'bulk-update' => LinehaulTripDecline::whereKey($history->id)->update(['reason' => 'Overwrite']),
                    'bulk-delete' => LinehaulTripDecline::whereKey($history->id)->delete(),
                };
                $this->fail('Immutable history was changed.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be', $exception->getMessage());
            }
        }
        $this->assertSame('Vehicle problem', $history->fresh()->reason);
    }

    public function test_audit_failure_rolls_back_decline_unassignment_and_notification(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'assignment' => $assignment, 'provider' => $provider] = $this->fixture();
        $dispatcher = ManifestAuditLog::getEventDispatcher();
        ManifestAuditLog::setEventDispatcher(clone $dispatcher);
        ManifestAuditLog::creating(function ($log): void {
            if ($log->action === 'courier_decline') {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            app(LinehaulDeclineService::class)->decline($driver, $manifest, $this->body(), request());
            $this->fail('Audit failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            ManifestAuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, LinehaulTripDecline::count());
        $this->assertNull($assignment->fresh()->declined_at);
        $this->assertSame(0, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierTripDeclined::class)->count());
        $this->assertTrue(LinehaulDutyService::busy($driver));
    }

    public function test_decline_uses_the_existing_authenticated_throttle(): void
    {
        ['driver' => $driver, 'manifest' => $manifest] = $this->fixture();
        $this->api($driver);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson($this->url($manifest), [])->assertUnprocessable();
        }
        $this->postJson($this->url($manifest), $this->body())->assertStatus(429);

        $this->assertNull(LinehaulAssignment::sole()->declined_at);
    }

    public function test_declined_courier_can_take_a_different_trip_with_one_current_offer(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'station' => $station, 'province' => $province] = $this->fixture();
        $this->api($driver)->postJson($this->url($manifest), $this->body())->assertOk();
        $otherTrip = app(ManifestService::class)->create($provider, $station->id, $province->id);
        app(LinehaulService::class)->assign($provider, $otherTrip, $driver);
        $this->assertSame(1, LinehaulAssignment::currentOffer()->where('linehaul_driver_id', $driver->id)->count());
        $this->getJson('/api/courier/trips')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $otherTrip->id)->assertJsonPath('data.0.status', 'Assigned')
            ->assertJsonPath('data.1.id', $manifest->id)->assertJsonPath('data.1.status', 'Declined');
    }

    public function test_outgoing_list_includes_owned_station_batches_without_other_provider_records(): void
    {
        ['manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $other = $this->operationalHandoffProvider('Other Decline Carrier', '045800000', 'Rizal', '045802000', 'Antipolo', '045802001', 'Bagong Nayon', '040000000', 'CALABARZON');
        $foreign = app(ManifestService::class)->create($other['provider'], $other['station']->id, $other['province']->id);
        $this->actingAs($provider, 'logistics')->get(route('logistics.manifests.index'))->assertOk()
            ->assertSee('Batch #'.$manifest->id)->assertDontSee('Batch #'.$foreign->id);
    }
}
