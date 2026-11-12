<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\HubDelayService;
use App\Services\Logistics\LinehaulService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsLinehaulBreakdowns;
use Tests\TestCase;

class HubDelayTest extends TestCase
{
    use BootstrapsLinehaulBreakdowns, LazilyRefreshDatabase;

    public function test_hub_clock_escalates_once_at_each_threshold_and_review_does_not_reset_it(): void
    {
        ['parcel' => $parcel, 'provider' => $provider, 'station' => $station] = $this->fixture(false);
        $parcel->forceFill(['custody_provider_id' => $provider->id])->save();
        $receivedAt = StatusHistory::record($parcel, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            actor: OrderActor::Logistics, actorId: $provider->id)->created_at;
        $admin = Admin::factory()->create();
        $buyer = $parcel->order->buyer;
        $delays = app(HubDelayService::class);

        $this->travelTo($receivedAt->copy()->addHours(23));
        $this->artisan('parcels:check-hub-delays')->expectsOutput('Hub delay cases updated: 0.')->assertSuccessful();
        $this->assertSame(0, $parcel->incidentSet()->count());
        $this->travelTo($receivedAt->copy()->addHours(24));
        $this->assertSame(1, $delays->sweep());
        $case = $parcel->incidentSet()->sole();
        $this->assertSame(24, $case->custody_snapshot['level']);
        $this->assertSame($station->id, $case->custody_snapshot['hub_id']);
        $this->assertFalse(ParcelIncidentService::movementBlockers($parcel)->exists());
        $this->assertSame(0, $provider->notifications()->count());
        $this->assertSame(0, $buyer->notifications()->count());

        $this->travelTo($receivedAt->copy()->addHours(48));
        $this->assertSame(1, $delays->sweep());
        $this->assertSame(0, $delays->sweep());
        $this->assertSame(48, $case->fresh()->custody_snapshot['level']);
        $this->assertSame(1, $provider->notifications()->count());
        $this->assertSame('hub_delay', $buyer->notifications()->sole()->data['type']);
        $this->actingAs(LogisticsProvider::factory()->create(['status' => 'approved']), 'logistics')
            ->post(route('logistics.exceptions.review', $case), ['action_owner' => 'Other company', 'review_note' => 'No action.'])
            ->assertForbidden();
        $this->actingAs($provider, 'logistics')->get(route('logistics.exceptions.index', ['type' => 'hub_delay']))
            ->assertOk()->assertSee('Logistics escalation');
        $this->get(route('logistics.exceptions.show', $case))->assertOk()->assertSee($station->name)->assertSee('Review deadline');
        $this->post(route('logistics.exceptions.review', $case), ['action_owner' => 'Shift lead', 'review_note' => 'Check alternate dispatch.'])
            ->assertSessionHas('success');

        $this->travelTo($receivedAt->copy()->addHours(120));
        $this->assertSame(1, $delays->sweep());
        $this->assertSame(120, $case->fresh()->custody_snapshot['level']);
        $this->get(route('logistics.exceptions.show', $case))->assertOk()->assertSee('Review required');
        $this->travelTo($receivedAt->copy()->addHours(168));
        $this->assertSame(1, $delays->sweep());
        $this->assertSame(0, $delays->sweep());
        $this->assertSame(168, $case->fresh()->custody_snapshot['level']);
        $this->assertSame(4, $case->events()->where('event', 'hub_delay_level')->count());
        $this->assertSame(1, $buyer->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertSame($receivedAt->toIso8601String(), $case->fresh()->custody_snapshot['waiting_since']);
        $this->actingAs($admin, 'admin')->get(route('admin.incidents.index'))->assertOk()->assertSee('Assessment due');
        $this->get(route('admin.incidents.detail', $case))->assertOk()->assertSee('Review required');
        $this->post(route('admin.incidents.resolve', $case), ['action_owner' => 'Ops lead', 'review_note' => 'Assess the next route and contact support.'])
            ->assertSessionHas('success');
        $this->assertSame(168, $case->fresh()->resolution_context['level']);
        $this->assertTrue($case->fresh()->isActive());
        $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('hub-delay-test', ['buyer'])->plainTextToken)
            ->getJson('/api/buyer/notifications')->assertOk()
            ->assertJsonPath('data.data.0.title', 'Parcel delayed at a hub')
            ->assertJsonPath('data.data.0.order_id', $parcel->order_id);
    }

    public function test_dispatch_ends_origin_wait_and_only_received_items_start_a_new_hub_clock(): void
    {
        ['parcel' => $parcel, 'provider' => $provider, 'station' => $station, 'province' => $province,
            'manifest' => $manifest, 'driver' => $driver] = $this->fixture(false);
        $parcel->forceFill(['custody_provider_id' => $provider->id])->save();
        $manifests = app(ManifestService::class);
        $unreceived = SellerOrder::factory()->create(['tracking_number' => 'DELAY-UNRECEIVED']);
        $unreceived->forceFill([
            'status' => OrderStatus::AtSortingCenter, 'origin_station_hub_id' => $station->id,
            'custody_hub_id' => $station->id, 'custody_provider_id' => $provider->id,
            'carrier_provider_id' => $provider->id, 'handover_at' => now(),
        ])->save();
        foreach ([$parcel, $unreceived] as $shipment) {
            StatusHistory::record($shipment, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
                actor: OrderActor::Logistics, actorId: $provider->id)->forceFill(['created_at' => now()->subDays(2)])->save();
        }
        $delays = app(HubDelayService::class);
        $this->assertSame(2, $delays->sweep());
        $manifests->addParcel($manifest, $unreceived);
        $linehaul = app(LinehaulService::class);
        $linehaul->act($driver, $manifest, 'scan', ['tracking_number' => $unreceived->tracking_number, 'method' => 'manual'], request());
        $linehaul->act($driver, $manifest, 'depart', ['seal_number' => 'BH-0000771'], request());
        $this->assertSame(2, $delays->sweep());
        $this->assertSame(0, Incidents::query()->where('type', 'hub_delay')->where('status', 'open')->count());

        $item = $manifest->items()->where('seller_order_id', $parcel->id)->firstOrFail();
        $scan = $manifests->verifyScan($manifest, $provider, $parcel->tracking_number, ManifestService::PHASE_RECEIVE);
        $this->actingAs($provider, 'logistics')->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $scan['verification_token'], 'observed_seal' => 'BH-0000771',
        ])->assertSessionHasNoErrors();
        $this->travel(24)->hours();
        $this->assertSame(1, $delays->sweep());
        $this->assertSame(0, $delays->sweep());
        $case = $parcel->incidentSet()->where('status', 'open')->sole();
        $this->assertSame($province->id, $case->custody_snapshot['hub_id']);
        $this->assertSame(24, $case->custody_snapshot['level']);
        $this->assertSame('HUB_WAIT_ENDED', $unreceived->incidentSet()->sole()->resolution);
    }
}
