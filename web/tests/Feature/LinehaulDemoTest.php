<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use App\Policies\LinehaulPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulDemoTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(bool $assign = true): array
    {
        $this->withoutVite();
        $network = $this->operationalHandoffProvider('Courier Demo Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Demo Courier', 'email' => 'courier@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();
        app(\App\Services\Logistics\LinehaulDutyService::class)->change($driver, 'on', [
            'latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(\App\Services\Logistics\LinehaulDutyService::ITEMS, true),
        ]);
        $parcel = SellerOrder::factory()->create(['tracking_number' => 'TRK-LINEHAUL-001']);
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value, 'origin_station_hub_id' => $network['station']->id,
            'custody_hub_id' => $network['station']->id, 'carrier_provider_id' => $network['provider']->id,
            'custody_provider_id' => null, 'handover_at' => now()])->save();
        $manifests = app(ManifestService::class);
        $manifest = $manifests->create($network['provider'], $network['station']->id, $network['province']->id);
        $item = $manifests->addParcel($manifest, $parcel);
        if ($assign) {
            app(LinehaulService::class)->assign($network['provider'], $manifest, $driver);
        }

        return [...$network, ...compact('driver', 'manifest', 'item', 'parcel')];
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('test', ['linehaul'])->plainTextToken);
    }

    public function test_login_requires_real_courier_credentials_and_active_company(): void
    {
        ['driver' => $driver, 'provider' => $provider] = $this->fixture();
        $this->postJson('/api/courier/login', [])->assertUnprocessable();
        $this->postJson('/api/courier/login', ['email' => $driver->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/courier/login', ['email' => $driver->email, 'password' => 'Courier@Test2026'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at']]);
        $provider->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/courier/login', ['email' => $driver->email, 'password' => 'Courier@Test2026'])->assertForbidden();
        $this->api($driver)->getJson('/api/courier/trips')->assertForbidden();
    }

    public function test_buyer_and_courier_tokens_cannot_cross_roles(): void
    {
        ['driver' => $driver] = $this->fixture();
        $this->getJson('/api/courier/trips')->assertUnauthorized();
        $buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        \Illuminate\Support\Facades\Route::get('/api/linehaul-role-probe', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'api.role:linehaul']);
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->getJson('/api/courier/trips')->assertForbidden();
        $this->getJson('/api/linehaul-role-probe')->assertForbidden();
        $rider = \App\Models\Rider\Rider::factory()->create();
        $this->withToken($rider->createToken('forged', ['linehaul'])->plainTextToken)->getJson('/api/courier/trips')->assertForbidden();
        $this->api($driver)->getJson('/api/rider/jobs')->assertForbidden();
        $this->getJson('/api/buyer/cart')->assertForbidden();
        $this->getJson('/api/courier/profile')->assertOk()->assertJsonPath('data.email', $driver->email)->assertJsonMissingPath('data.password');
        $this->postJson('/api/courier/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/courier/trips')->assertUnauthorized();
    }

    public function test_only_assigned_courier_can_view_or_act_and_policy_checks_tenant(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider] = $this->fixture();
        $other = $driver->replicate()->forceFill(['email' => 'other@example.com']);
        $other->save();
        $this->api($other)->getJson('/api/courier/trips')->assertOk()->assertJsonCount(0, 'data');
        foreach (['accept', 'scan', 'depart', 'arrive', 'exception-note'] as $action) {
            $this->postJson('/api/courier/trips/'.$manifest->id.'/'.$action)->assertForbidden();
        }
        $policy = new LinehaulPolicy;
        $this->assertTrue($policy->access($driver, $manifest));
        $this->assertFalse($policy->access($other, $manifest));
        $other->forceFill(['logistics_provider_id' => 999999]);
        $this->assertFalse($policy->access($other, $manifest));
        $this->assertTrue($policy->assign($provider, $manifest));
        $this->assertFalse($policy->assign(\App\Models\Logistics\LogisticsProvider::factory()->create(), $manifest));
        $this->assertDatabaseHas('manifests', ['id' => $manifest->id, 'status' => 'DRAFT']);
    }

    public function test_assigned_trip_exposes_only_saved_hub_addresses_with_empty_address_fallback(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'station' => $station, 'province' => $province] = $this->fixture();
        $station->forceFill(['building' => 'Origin Warehouse', 'street' => 'Mabini Street', 'landmark' => null,
            'barangay_name' => 'Poblacion', 'municipality' => 'Calamba', 'province_name' => 'Laguna', 'region_name' => 'CALABARZON', 'postal_code' => '4027'])->save();
        $province->forceFill(['building' => 'Destination Warehouse', 'street' => 'Rizal Avenue', 'landmark' => null,
            'barangay_name' => 'Poblacion', 'municipality' => 'Calamba', 'province_name' => 'Laguna', 'region_name' => 'CALABARZON', 'postal_code' => '4027'])->save();
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()
            ->assertJsonPath('data.0.origin_address', 'Origin Warehouse, Mabini Street, Poblacion, Calamba, Laguna, CALABARZON, 4027')
            ->assertJsonPath('data.0.destination_address', 'Destination Warehouse, Rizal Avenue, Poblacion, Calamba, Laguna, CALABARZON, 4027')
            ->assertJsonMissingPath('data.0.latitude')->assertJsonMissingPath('data.0.buyer_address');
        $other = $driver->replicate()->forceFill(['email' => 'address-private@example.com']);
        $other->save();
        $this->api($other)->getJson('/api/courier/trips')->assertOk()->assertJsonCount(0, 'data')
            ->assertDontSee('Destination Warehouse');
        $province->forceFill(array_fill_keys(['building', 'street', 'landmark', 'barangay_name', 'municipality', 'province_name', 'region_name', 'postal_code', 'location_details'], ''))->save();
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.destination_address', null);
        $this->assertDatabaseHas('manifests', ['id' => $manifest->id, 'status' => 'DRAFT']);
    }

    public function test_one_active_trip_and_company_owned_assignment(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'station' => $station, 'province' => $province] = $this->fixture(false);
        $this->actingAs($provider, 'logistics')->post(route('logistics.manifests.courier', $manifest), [])->assertSessionHasErrors('driver_id');
        $this->post(route('logistics.manifests.courier', $manifest), ['driver_id' => $driver->id])->assertRedirect();
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()->assertSee('Demo Courier');
        $second = app(ManifestService::class)->create($provider, $station->id, $province->id);
        $this->post(route('logistics.manifests.courier', $second), ['driver_id' => $driver->id])->assertSessionHasErrors('trip');
        $this->actingAs(\App\Models\Logistics\LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]), 'logistics')
            ->post(route('logistics.manifests.courier', $manifest), ['driver_id' => $driver->id])->assertForbidden();
        $this->assertDatabaseCount('linehaul_assignments', 1);
        $this->assertDatabaseHas('manifest_audit_logs', ['manifest_id' => $manifest->id, 'action' => 'courier_assign', 'actor_id' => $provider->id]);
    }

    public function test_live_trip_requires_acceptance_every_scan_and_valid_seal_then_logistics_receives(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'item' => $item, 'parcel' => $parcel] = $this->fixture();
        $this->api($driver);
        $path = '/api/courier/trips/'.$manifest->id;
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertUnprocessable();
        $this->postJson($path.'/accept')->assertOk()->assertJsonPath('data.status', 'Loading');
        $this->postJson($path.'/accept')->assertOk();
        $this->assertSame(1, ManifestAuditLog::where('action', 'courier_accept')->count());
        $this->postJson($path.'/depart', ['seal_number' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('seal_number');
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertUnprocessable();
        $this->postJson($path.'/scan', ['tracking_number' => 'NOT-ON-TRIP'])->assertUnprocessable();
        $scan = $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number, 'method' => 'manual'])->assertOk();
        $scan->assertJsonPath('data.items.0.loaded', true)->assertJsonMissingPath('data.loading_tokens');
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk()->assertJsonCount(1, 'data.items');
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk()->assertJsonPath('data.status', 'In Transit');
        $this->assertDatabaseHas('manifests', ['id' => $manifest->id, 'dispatched_by_type' => 'linehaul', 'dispatched_by_id' => $driver->id]);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertUnprocessable();
        $this->postJson($path.'/arrive')->assertOk()->assertJsonPath('data.status', 'Arrived');
        $this->postJson($path.'/receive')->assertNotFound();
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertNull($item->fresh()->received_at);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics');
        $token = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcel->tracking_number, 'phase' => 'receive', 'method' => 'manual'])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $token, 'observed_seal' => 'BH-0004521'])->assertRedirect();
        $this->assertSame(ManifestStatus::Received, $manifest->fresh()->status);
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.status', 'Completed')
            ->assertJsonCount(1, 'data.0.items')->assertJsonPath('data.0.items.0.received', true);
    }

    public function test_multi_parcel_trip_requires_all_loading_and_receiving_scans(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'item' => $item, 'parcel' => $parcel] = $this->fixture();
        $second = SellerOrder::factory()->create(['tracking_number' => 'TRK-LINEHAUL-002']);
        $second->forceFill($parcel->only([
            'status', 'origin_station_hub_id', 'custody_hub_id', 'carrier_provider_id', 'custody_provider_id', 'handover_at',
        ]))->save();
        $secondItem = app(ManifestService::class)->addParcel($manifest, $second);
        $path = '/api/courier/trips/'.$manifest->id;
        $this->api($driver)->postJson($path.'/accept')->assertOk();
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertUnprocessable();
        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);
        $this->assertNull($item->fresh()->dispatched_at);
        $this->assertNull($secondItem->fresh()->dispatched_at);
        $this->postJson($path.'/scan', ['tracking_number' => $second->tracking_number, 'method' => 'camera'])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk()->assertJsonPath('data.status', 'In Transit');
        $this->postJson($path.'/arrive')->assertOk()->assertJsonPath('data.status', 'Arrived');

        foreach ([[$parcel, $item], [$second, $secondItem]] as $index => [$receivingParcel, $receivingItem]) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->actingAs($provider, 'logistics');
            $token = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
                'tracking_number' => $receivingParcel->tracking_number, 'phase' => 'receive', 'method' => 'manual',
            ])->assertOk()->json('verification_token');
            $this->post(route('logistics.manifests.receive.store', [$manifest, $receivingItem]), [
                'verification_token' => $token, ...($index === 0 ? ['observed_seal' => 'BH-0004521'] : []),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->api($driver)->getJson('/api/courier/trips')->assertOk()
                ->assertJsonPath('data.0.status', $index === 0 ? 'Arrived' : 'Completed');
        }
        $this->assertNotNull($item->fresh()->received_at);
        $this->assertNotNull($secondItem->fresh()->received_at);
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'seal_confirmed')->count());
    }

    public function test_seal_mismatch_requires_note_before_receipt_and_is_audited_as_an_issue(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'item' => $item, 'parcel' => $parcel] = $this->fixture();
        $path = '/api/courier/trips/'.$manifest->id;
        $this->api($driver)->postJson($path.'/accept')->assertOk();
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk();
        $this->postJson($path.'/arrive')->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics');
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'receive', 'method' => 'manual',
        ])->assertOk()->assertJsonPath('actions.0.requires_seal', true);
        $token = $scan->json('verification_token');
        $url = route('logistics.manifests.receive.store', [$manifest, $item]);
        foreach ([[], ['observed_seal' => 'invalid']] as $input) {
            $this->postJson($url, ['verification_token' => $token, ...$input])->assertUnprocessable()->assertJsonValidationErrors('observed_seal');
        }
        foreach (['', '   ', str_repeat('x', 501)] as $note) {
            $this->postJson($url, ['verification_token' => $token, 'observed_seal' => 'BH-0004522', 'seal_note' => $note])
                ->assertUnprocessable()->assertJsonValidationErrors('seal_note');
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
            $this->assertNull(\App\Models\Logistics\LinehaulAssignment::where('manifest_id', $manifest->id)->sole()->seal_receipt);
        }
        foreach (['missing', 'damaged'] as $issue) {
            $this->postJson($url, ['verification_token' => $token, 'issue_type' => $issue, 'issue_note' => 'Rehearsal exception.', 'missing_confirmed' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('observed_seal');
            $this->assertNull($item->fresh()->issue_type);
            $this->assertNull($item->fresh()->received_at);
        }
        $this->post($url, ['verification_token' => $token, 'observed_seal' => 'BH-0004522', 'seal_note' => 'Seal differs from departure.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $assignment = \App\Models\Logistics\LinehaulAssignment::where('manifest_id', $manifest->id)->sole();
        $this->assertSame('BH-0004521', $assignment->seal_number);
        $this->assertSame('BH-0004522', $assignment->seal_receipt['observed']);
        $this->assertSame('Seal differs from departure.', $assignment->seal_receipt['note']);
        $this->assertSame($provider->id, $assignment->seal_receipt['actor_id']);
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'seal_discrepancy')->count());
        $this->assertSame(1, $provider->notifications()->where('type', \App\Notifications\Logistics\CourierSealDiscrepancy::class)->count());
        $this->get(route('logistics.manifests.receive.index', $manifest))->assertOk()->assertSee('Seal discrepancy recorded')->assertSee('Seal differs from departure.');
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.status', 'Received with Issues')
            ->assertJsonCount(1, 'data.0.items')->assertJsonPath('data.0.items.0.received', true);
    }

    public function test_missing_parcel_needs_confirmation_and_remains_visible_after_other_parcels_arrive(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'item' => $item, 'parcel' => $parcel] = $this->fixture();
        $this->postJson('/api/courier/trips/'.$manifest->id.'/exception-note', ['tracking_number' => $parcel->tracking_number, 'note' => 'Unauthenticated'])->assertUnauthorized();
        $second = SellerOrder::factory()->create(['tracking_number' => 'TRK-EXCEPTION-002']);
        $second->forceFill($parcel->only(['status', 'origin_station_hub_id', 'custody_hub_id', 'carrier_provider_id', 'custody_provider_id', 'handover_at']))->save();
        $secondItem = app(ManifestService::class)->addParcel($manifest, $second);
        $path = '/api/courier/trips/'.$manifest->id;
        $this->api($driver)->postJson($path.'/accept')->assertOk();
        foreach ([$parcel, $second] as $loading) {
            $this->postJson($path.'/scan', ['tracking_number' => $loading->tracking_number])->assertOk();
        }
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk();
        $this->postJson($path.'/arrive')->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($provider, 'logistics');
        $this->get(route('logistics.manifests.receive.index', $manifest))->assertOk()->assertSee('Report this parcel missing');
        $url = route('logistics.manifests.receive.store', [$manifest, $item]);
        $input = ['issue_type' => 'missing', 'issue_note' => 'Not found in the truck.', 'observed_seal' => 'BH-0004521'];
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('missing_confirmed');
        $this->postJson($url, [...$input, 'missing_confirmed' => true, 'issue_note' => '   '])->assertUnprocessable()->assertJsonValidationErrors('issue_note');
        $this->assertNull($item->fresh()->released_at);
        $this->post($url, [...$input, 'missing_confirmed' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->received_at);
        $this->assertSame('missing', $item->fresh()->issue_type);
        $this->assertSame($manifest->origin_hub_id, $parcel->fresh()->custody_hub_id);
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertSame(['total' => 2, 'received' => 0, 'pending' => 1], app(ManifestService::class)->counts($manifest));
        $incident = \App\Models\Shared\Incidents::where('incident_seller_order_id', $parcel->id)->sole();
        foreach ([$provider, $parcel->seller, $parcel->order->buyer] as $recipient) {
            \Illuminate\Support\Facades\Notification::assertSentTo($recipient, \App\Notifications\Incident\IncidentReported::class);
        }
        $this->post($url, [...$input, 'missing_confirmed' => '1'])->assertRedirect();
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'parcel_missing')->count());
        $this->assertSame(1, \App\Models\Shared\Incidents::where('incident_seller_order_id', $parcel->id)->count());
        $token = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $second->tracking_number, 'phase' => 'receive'])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $secondItem]), ['verification_token' => $token])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ManifestStatus::ReceivedWithExceptions, $manifest->fresh()->status);
        $this->get(route('logistics.manifests.receive.index', $manifest))->assertOk()->assertSee('Not found in the truck.');
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.status', 'Received with Issues')
            ->assertJsonCount(2, 'data.0.items')->assertJsonPath('data.0.items.0.issue', 'missing')->assertJsonPath('data.0.items.0.received', false);
        $this->assertTrue($incident->fresh()->isActive());
        $this->postJson($path.'/exception-note', ['tracking_number' => $parcel->tracking_number])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($path.'/exception-note', ['tracking_number' => 'ANOTHER-TRIP', 'note' => 'My note'])->assertUnprocessable();
        $note = ['tracking_number' => $parcel->tracking_number, 'note' => 'Loaded at origin; not found at unloading.'];
        $this->postJson($path.'/exception-note', $note)->assertOk()->assertJsonPath('data.items.0.courier_note', $note['note']);
        $this->postJson($path.'/exception-note', $note)->assertOk();
        $this->postJson($path.'/exception-note', [...$note, 'note' => 'Overwrite'])->assertUnprocessable();
        $this->assertSame(1, $incident->events()->where('event', 'courier_handover_note')->count());
        $this->assertDatabaseHas('manifest_audit_logs', ['manifest_id' => $manifest->id, 'action' => 'courier_note', 'actor_type' => 'linehaul', 'actor_id' => $driver->id]);
    }

    public function test_damaged_parcel_requires_private_photo_and_cannot_be_reported_by_another_provider(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        ['driver' => $driver, 'manifest' => $manifest, 'provider' => $provider, 'item' => $item, 'parcel' => $parcel] = $this->fixture();
        $path = '/api/courier/trips/'.$manifest->id;
        $this->api($driver)->postJson($path.'/accept')->assertOk();
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $other = \App\Models\Logistics\LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $url = route('logistics.manifests.receive.store', [$manifest, $item]);
        $this->actingAs($other, 'logistics')->postJson($url, ['issue_type' => 'missing'])->assertNotFound();
        $this->actingAs($provider, 'logistics');
        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcel->tracking_number, 'phase' => 'receive'])
            ->assertOk()->assertJsonPath('actions.0.can_report_damage', true);
        $input = ['verification_token' => $scan->json('verification_token'), 'issue_type' => 'damaged',
            'issue_note' => 'Box torn at unloading.', 'observed_seal' => 'BH-0004521'];
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertNull($item->fresh()->received_at);
        $this->assertNull($item->fresh()->issue_type);
        $this->assertNull(\App\Models\Logistics\LinehaulAssignment::where('manifest_id', $manifest->id)->sole()->seal_receipt);
        $this->postJson($url, [...$input, 'photo' => \Illuminate\Http\UploadedFile::fake()->create('fake.jpg', 10, 'text/plain')])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->postJson($url, [...$input, 'photo' => \Illuminate\Http\UploadedFile::fake()->image('large.jpg')->size(5121)])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post($url, [...$input, 'photo' => \Illuminate\Http\UploadedFile::fake()->image('damage.jpg')])->assertRedirect()->assertSessionHasNoErrors();
        $incident = \App\Models\Shared\Incidents::where('incident_seller_order_id', $parcel->id)->sole();
        $this->assertSame('local', $incident->photo_disk);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($incident->photo_path);
        $this->assertArrayNotHasKey('photo_path', $incident->toArray());
        $this->assertSame('damaged', $item->fresh()->issue_type);
        $this->assertNotNull($item->fresh()->received_at);
        $this->assertSame($manifest->destination_hub_id, $parcel->fresh()->custody_hub_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertSame(ManifestStatus::ReceivedWithExceptions, $manifest->fresh()->status);
        $this->assertNotNull($manifest->fresh()->received_at);
        $this->assertSame(1, ManifestAuditLog::where('manifest_id', $manifest->id)->where('action', 'parcel_damaged')->count());
        $this->api($driver)->getJson('/api/courier/trips')->assertOk()->assertJsonPath('data.0.status', 'Received with Issues')
            ->assertJsonPath('data.0.items.0.issue', 'damaged')->assertJsonMissingPath('data.0.items.0.photo_path');
    }

    public function test_demo_provisioning_never_overwrites_accounts(): void
    {
        ['provider' => $provider, 'station' => $station, 'driver' => $driver] = $this->fixture();
        $options = ['--provider' => $provider->id, '--hub' => $station->id, '--email' => $driver->email, '--name' => 'Replacement'];
        $before = $driver->fresh()->getAttributes();
        $this->artisan('linehaul:provision', $options)->expectsOutput('That email is already registered. No account was changed.')->assertFailed();
        $this->assertSame($before, $driver->fresh()->getAttributes());
        $this->artisan('linehaul:provision', [...$options, '--email' => 'newcourier@example.com'])
            ->expectsQuestion('Courier password', 'Courier@Test2026')->assertSuccessful();
        $created = LinehaulDriver::where('email', 'newcourier@example.com')->sole();
        $this->assertTrue($created->isOperational());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Courier@Test2026', $created->password));
    }

    public function test_courier_endpoints_are_throttled(): void
    {
        ['driver' => $driver] = $this->fixture();
        $this->api($driver);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson('/api/courier/profile')->assertOk();
        }
        $this->getJson('/api/courier/profile')->assertStatus(429);
    }

    public function test_expired_scans_require_rescanning_and_never_dispatch_the_manifest(): void
    {
        ['driver' => $driver, 'manifest' => $manifest, 'parcel' => $parcel] = $this->fixture();
        $this->api($driver);
        $path = '/api/courier/trips/'.$manifest->id;
        $this->postJson($path.'/accept')->assertOk();
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        $assignment = \App\Models\Logistics\LinehaulAssignment::where('manifest_id', $manifest->id)->sole();
        $token = array_values($assignment->loading_tokens)[0];
        $this->assertStringNotContainsString($token, $assignment->getRawOriginal('loading_tokens'));
        $this->travel(11)->minutes();
        $this->postJson('/api/courier/duty/heartbeat', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertUnprocessable()->assertJsonValidationErrors('verification_token');
        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);
        $this->assertNull($assignment->fresh()->seal_number);
        $this->postJson($path.'/scan', ['tracking_number' => $parcel->tracking_number])->assertOk();
        $this->postJson($path.'/depart', ['seal_number' => 'BH-0004521'])->assertOk()->assertJsonPath('data.status', 'In Transit');
    }

    public function test_demo_provisioning_is_refused_in_production(): void
    {
        $this->assertDatabaseCount('linehaul_drivers', 0);
        $this->app['env'] = 'production';
        try {
            $this->artisan('linehaul:provision')->expectsOutput('Courier demo provisioning is available only in local/testing environments.')->assertFailed();
            $this->assertDatabaseCount('linehaul_drivers', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
