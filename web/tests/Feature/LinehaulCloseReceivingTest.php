<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulCloseReceivingTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(int $count = 2, bool $arrive = true): array
    {
        $this->withoutVite();
        $network = $this->operationalHandoffProvider('Bulk Demo Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Bulk Courier', 'email' => 'bulk@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        app(LinehaulDutyService::class)->change($driver, 'on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(LinehaulDutyService::ITEMS, true)]);
        $manifest = app(ManifestService::class)->create($network['provider'], $network['station']->id, $network['province']->id);
        $parcels = []; $items = [];
        for ($number = 1; $number <= $count; $number++) {
            $parcel = SellerOrder::factory()->create(['tracking_number' => 'BULK-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT)]);
            $parcel->forceFill(['status' => OrderStatus::AtSortingCenter, 'origin_station_hub_id' => $network['station']->id,
                'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id,
                'handover_at' => now()])->save();
            $parcels[] = $parcel;
            $items[] = app(ManifestService::class)->addParcel($manifest, $parcel);
        }
        app(LinehaulService::class)->assign($network['provider'], $manifest, $driver->fresh());
        $this->withToken($driver->createToken('bulk', ['linehaul'])->plainTextToken);
        $path = '/api/courier/trips/'.$manifest->id;
        $this->postJson($path.'/accept')->assertOk();
        foreach ($parcels as $parcel) {
            $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        }
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk();
        if ($arrive) $this->postJson($path.'/arrive')->assertOk();
        return [...$network, ...compact('driver', 'manifest', 'parcels', 'items')];
    }

    private function logistics($provider): static
    {
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        return $this->actingAs($provider, 'logistics');
    }

    private function payload(array $items): array
    {
        return ['pending_item_ids' => array_map(fn ($item) => $item->id, $items), 'close_confirmed' => '1',
            'issue_note' => 'Not found when unloading the truck.', 'observed_seal' => 'BH-0004521'];
    }

    public function test_only_owning_logistics_can_close_and_policy_rejects_foreign_company(): void
    {
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items] = $this->fixture();
        $url = route('logistics.manifests.receive.close', $manifest);
        // A mobile Courier token is not a Logistics web session.
        $this->postJson($url, $this->payload($items))->assertUnauthorized();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->assertFalse(Gate::forUser($other)->allows('linehaul-close-receiving', $manifest));
        $this->assertTrue(Gate::forUser($provider)->allows('linehaul-close-receiving', $manifest));
        $this->logistics($other)->postJson($url, $this->payload($items))->assertForbidden();
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
        $provider->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse(Gate::forUser($provider)->allows('linehaul-close-receiving', $manifest));
    }

    public function test_bulk_close_preserves_receipts_creates_incidents_and_is_idempotent(): void
    {
        Notification::fake();
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items, 'parcels' => $parcels, 'driver' => $driver] = $this->fixture(3);
        $this->logistics($provider);
        $token = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcels[0]->tracking_number, 'phase' => 'receive'])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $items[0]]), ['verification_token' => $token, 'observed_seal' => 'BH-0004521'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('logistics.manifests.receive.index', $manifest))->assertOk()->assertSee('Review unreceived parcels before closing')->assertSee('BULK-0002')->assertSee('BULK-0003');
        $input = $this->payload([$items[1], $items[2]]);
        $url = route('logistics.manifests.receive.close', $manifest);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.status', 'RECEIVED_WITH_EXCEPTIONS')
            ->assertJsonPath('data.received_count', 1)->assertJsonPath('data.missing_count', 2)->assertJsonPath('data.pending_count', 0)->assertJsonCount(3, 'data.items');
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, [...$input, 'issue_note' => 'Attempted overwrite'])->assertOk();
        $this->assertNotNull($items[0]->fresh()->received_at);
        foreach ([$items[1], $items[2]] as $item) {
            $this->assertSame('missing', $item->fresh()->issue_type);
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame($manifest->origin_hub_id, $item->sellerOrder->fresh()->custody_hub_id);
            $incident = \App\Models\Shared\Incidents::where('incident_seller_order_id', $item->seller_order_id)->sole();
            $this->assertSame('Not found when unloading the truck.', $incident->description);
            foreach ([$provider, $item->sellerOrder->seller, $item->sellerOrder->order->buyer] as $recipient) {
                Notification::assertSentTo($recipient, \App\Notifications\Incident\IncidentReported::class);
            }
        }
        $this->assertDatabaseCount('incidents', 2);
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'receiving_closed')->count());
        $this->assertNotNull($manifest->fresh()->received_at);
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->withToken($driver->createToken('after-close', ['linehaul'])->plainTextToken)->getJson('/api/courier/trips')
            ->assertOk()->assertJsonPath('data.0.status', 'Received with Issues')->assertJsonCount(3, 'data.0.items')
            ->assertJsonPath('data.0.items.0.tracking_number', $parcels[0]->tracking_number)->assertJsonPath('data.0.items.1.issue', 'missing');
        $noteReply = $this->postJson('/api/courier/trips/'.$manifest->id.'/exception-note', ['tracking_number' => $parcels[1]->tracking_number, 'note' => 'Loaded at origin, absent at unloading.'])->assertOk();
        $notedParcel = collect($noteReply->json('data.items'))->firstWhere('tracking_number', $parcels[1]->tracking_number);
        $this->assertSame('Loaded at origin, absent at unloading.', $notedParcel['courier_note'], json_encode($noteReply->json('data.items')));
        $this->assertSame(OrderStatus::AtSortingCenter, $parcels[1]->fresh()->status);
    }

    public function test_confirmation_note_and_exact_snapshot_are_required_and_stale_pages_refuse(): void
    {
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items, 'parcels' => $parcels] = $this->fixture();
        $this->logistics($provider); $input = $this->payload($items); $url = route('logistics.manifests.receive.close', $manifest);
        $this->postJson($url, [...$input, 'close_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('close_confirmed');
        $this->postJson($url, [...$input, 'issue_note' => '   '])->assertUnprocessable()->assertJsonValidationErrors('issue_note');
        $this->postJson($url, [...$input, 'pending_item_ids' => [$items[0]->id]])->assertUnprocessable()->assertJsonValidationErrors('pending_item_ids');
        $this->postJson($url, [...$input, 'pending_item_ids' => [$items[0]->id, $items[1]->id, 999999]])->assertUnprocessable()->assertJsonValidationErrors('pending_item_ids');
        $token = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcels[0]->tracking_number, 'phase' => 'receive'])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $items[0]]), ['verification_token' => $token, 'observed_seal' => 'BH-0004521'])->assertRedirect();
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('pending_item_ids');
        $this->assertNull($items[1]->fresh()->issue_type);
        $this->assertNull($items[1]->fresh()->released_at);
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_close_requires_reported_arrival_and_matching_or_explained_seal(): void
    {
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items, 'driver' => $driver] = $this->fixture(arrive: false);
        $this->logistics($provider); $url = route('logistics.manifests.receive.close', $manifest); $input = $this->payload($items);
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('manifest');
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->withToken($driver->createToken('arrive', ['linehaul'])->plainTextToken)->postJson('/api/courier/trips/'.$manifest->id.'/arrive')->assertOk();
        $manifest->forceFill(['dispatched_by_type' => 'logistics'])->save();
        $this->logistics($provider)->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('manifest');
        $manifest->forceFill(['dispatched_by_type' => 'linehaul'])->save();
        $this->logistics($provider)->postJson($url, [...$input, 'observed_seal' => 'BH-0004522'])->assertUnprocessable()->assertJsonValidationErrors('seal_note');
        $this->assertDatabaseCount('incidents', 0);
        $this->postJson($url, [...$input, 'observed_seal' => 'BH-0004522', 'seal_note' => 'Different seal observed.'])->assertOk()->assertJsonPath('data.missing_count', 2);
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'seal_discrepancy')->count());
    }

    public function test_mid_batch_failure_rolls_back_every_incident_receipt_and_notification(): void
    {
        Notification::fake();
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items] = $this->fixture();
        $real = app(\App\Services\Logistics\ParcelIncidentService::class); $calls = 0;
        $this->mock(\App\Services\Logistics\ParcelIncidentService::class, function ($mock) use ($real, &$calls): void {
            $mock->shouldReceive('reportOperational')->andReturnUsing(function ($parcel, $provider, $payload) use ($real, &$calls) {
                if (++$calls === 2) throw \Illuminate\Validation\ValidationException::withMessages(['incident' => 'Test failure on second parcel.']);
                return $real->reportOperational($parcel, $provider, $payload);
            });
        });
        $this->logistics($provider)->postJson(route('logistics.manifests.receive.close', $manifest), $this->payload($items))->assertUnprocessable();
        foreach ($items as $item) {
            $this->assertNull($item->fresh()->released_at); $this->assertNull($item->fresh()->issue_type);
        }
        $this->assertDatabaseCount('incidents', 0);
        $this->assertNull(\App\Models\Logistics\LinehaulAssignment::where('manifest_id', $manifest->id)->sole()->seal_receipt);
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertSame(0, ManifestAuditLog::where('manifest_id', $manifest->id)->whereIn('action', ['parcel_missing', 'receiving_closed', 'seal_confirmed'])->count());
        Notification::assertNothingSent();
    }

    public function test_existing_courier_note_survives_bulk_close_of_remaining_parcels(): void
    {
        Notification::fake();
        ['manifest' => $manifest, 'provider' => $provider, 'items' => $items, 'parcels' => $parcels, 'driver' => $driver] = $this->fixture();
        $this->logistics($provider)->post(route('logistics.manifests.receive.store', [$manifest, $items[0]]), [
            'issue_type' => 'missing', 'issue_note' => 'First parcel absent.', 'missing_confirmed' => true, 'observed_seal' => 'BH-0004521',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $token = $driver->createToken('retained-note', ['linehaul'])->plainTextToken;
        $this->withToken($token)->postJson('/api/courier/trips/'.$manifest->id.'/exception-note', [
            'tracking_number' => $parcels[0]->tracking_number, 'note' => 'Original Courier account of the handover.',
        ])->assertOk();
        $this->logistics($provider)->postJson(route('logistics.manifests.receive.close', $manifest), $this->payload([$items[1]]))
            ->assertOk()->assertJsonPath('data.missing_count', 2);
        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.status', 'Received with Issues')
            ->assertJsonPath('data.0.items.0.courier_note', 'Original Courier account of the handover.');
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'courier_note')->count());
    }

    public function test_close_route_is_throttled(): void
    {
        ['manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $this->logistics($provider); $url = route('logistics.manifests.receive.close', $manifest);
        for ($attempt = 0; $attempt < 20; $attempt++) $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, [])->assertTooManyRequests();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->logistics($other)->postJson($url, [])->assertForbidden();
        $this->assertDatabaseCount('incidents', 0);
    }
}
