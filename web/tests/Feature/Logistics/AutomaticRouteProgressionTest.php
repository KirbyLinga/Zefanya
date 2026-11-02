<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelLegService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class AutomaticRouteProgressionTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private function fixture(bool $crossProvider = false, bool $singleLeg = false): array
    {
        $this->withoutVite();
        $a = $this->operationalHandoffProvider('Origin Route', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        if ($crossProvider) {
            $b = $this->operationalHandoffProvider('Destination Route', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'NCR');
            $buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
            $this->activeHandoffLink($a['provider'], $b['provider'], Admin::factory()->create());
            $middle = $b['station'];
            $destination = $singleLeg ? $b['station'] : $b['province'];
        } else {
            $b = $a;
            $destination = $a['station']->replicate();
            $destination->fill(['name' => 'Final Station', 'barangay_code' => '0305401002', 'barangay_name' => 'Second Barangay'])->save();
            $area = $a['area']->replicate();
            $area->fill(['name' => 'Final Coverage', 'station_hub_id' => $destination->id, 'barangay_code' => '0305401002', 'barangay_name' => 'Second Barangay'])->save();
            $buyer = $this->buyerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401002', 'Second Barangay');
            $middle = $singleLeg ? $destination : $a['province'];
            $b['station'] = $destination;
            $b['area'] = $area;
        }
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-AUTO-ROUTE');
        $parcel->forceFill(['custody_provider_id' => $a['provider']->id, 'custody_hub_id' => $a['station']->id,
            'origin_station_hub_id' => $a['station']->id])->save();
        StatusHistory::record($parcel, OrderStatus::PickedUp, OrderStatus::AtSortingCenter, actor: OrderActor::Logistics, actorId: $a['provider']->id);
        $first = $this->connection($a['station'], $middle, $crossProvider ? 'AIR' : 'ROAD');
        $legs = [['hub_id' => $middle->id, 'transport_mode' => $first->transport_mode, 'transport_connection_id' => $first->id, 'operating_provider_id' => $first->operating_provider_id]];
        $second = null;
        if (! $singleLeg) {
            $second = $this->connection($middle, $destination, 'SEA');
            $legs[] = ['hub_id' => $destination->id, 'transport_mode' => 'SEA', 'transport_connection_id' => $second->id, 'operating_provider_id' => $second->operating_provider_id];
        }
        $route = ParcelRoute::query()->create(['seller_order_id' => $parcel->id, 'origin_hub_id' => $a['station']->id,
            'destination_hub_id' => $destination->id, 'legs' => $legs, 'current_leg_index' => 0]);

        return compact('a', 'b', 'parcel', 'route', 'middle', 'destination', 'first', 'second');
    }

    private function connection(LogisticsHub $origin, LogisticsHub $destination, string $mode): TransportConnection
    {
        return TransportConnection::query()->create(['origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id,
            'operating_provider_id' => $origin->logistics_provider_id, 'transport_mode' => $mode, 'is_active' => true]);
    }

    private function manifest(array $f, ?LogisticsHub $origin = null, ?LogisticsHub $destination = null): array
    {
        $service = app(ManifestService::class);
        $manifest = $service->create($f['a']['provider'], ($origin ?? $f['a']['station'])->id, ($destination ?? $f['middle'])->id);
        $item = $service->addParcel($manifest, $f['parcel']->fresh());
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $manifest = $service->dispatch($manifest, $f['a']['provider'], [$item->id => $scan['verification_token']]);

        return [$manifest, $item];
    }

    private function receive(array $f, Manifest $manifest, ManifestItem $item): array
    {
        $service = app(ManifestService::class);
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');

        return $service->receive($manifest, $f['a']['provider'], $item, $scan['verification_token']);
    }

    private function handoff(array $f, bool $dispatch = true): ParcelHandoff
    {
        $service = app(ParcelHandoffService::class);
        $handoff = $service->request($f['parcel'], $f['a']['provider']);
        $handoff = $service->accept($handoff, $f['b']['provider']);
        if ($dispatch) {
            $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
            $handoff = $service->dispatch($handoff, $f['a']['provider'], $scan['verification_token']);
        }

        return $handoff;
    }

    public function test_verified_manifest_receipt_advances_exactly_one_leg_and_exposes_next_destination(): void
    {
        $f = $this->fixture();
        $execution = app(RouteExecutionService::class);
        $this->assertSame($f['a']['station']->id, $execution->currentLocation($f['route'])->id);
        $this->assertSame($f['middle']->id, $execution->nextDestination($f['route'])->id);
        [$manifest, $item] = $this->manifest($f);
        $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        $this->assertNull($execution->currentLocation($f['route']));
        $this->assertTrue($execution->isInTransit($f['route']));

        $scan = app(ManifestService::class)->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $this->actingAs($f['a']['provider'], 'logistics')
            ->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $scan['verification_token']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $f['route']->fresh()->current_leg_index);
        $this->assertNull($f['route']->fresh()->route_completed_at);
        $this->assertSame($f['middle']->id, $execution->currentLocation($f['route'])->id);
        $this->assertSame($f['destination']->id, $execution->nextDestination($f['route'])->id);
        $this->assertSame('SEA', $execution->currentTransportMode($f['route']));
        $this->assertFalse(app(CourierAssignmentService::class)->deliveryReadyAtStation($f['parcel']->fresh(), $f['a']['provider']->id));
        $this->assertSame(1, $f['parcel']->statusHistories()->count());
        $this->assertDatabaseCount('manifests', 1);
        $this->assertDatabaseCount('parcel_handoffs', 0);
    }

    public function test_verified_cross_provider_receipt_advances_one_leg_and_acceptance_or_dispatch_alone_do_not(): void
    {
        $f = $this->fixture(crossProvider: true);
        $service = app(ParcelHandoffService::class);
        $handoff = $this->handoff($f, dispatch: false);
        $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        $this->assertSame($f['a']['station']->id, app(RouteExecutionService::class)->currentLocation($f['route'])->id);
        $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $f['a']['provider'], $scan['verification_token']);
        $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        $this->assertNull(app(RouteExecutionService::class)->currentLocation($f['route']));
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'receive');

        $this->actingAs($f['b']['provider'], 'logistics')->post(route('logistics.handoffs.receive', $handoff), ['verification_token' => $scan['verification_token']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $f['route']->fresh()->current_leg_index);
        $this->assertSame($f['b']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame($f['middle']->id, app(RouteExecutionService::class)->currentLocation($f['route'])->id);
    }

    public function test_duplicate_manifest_receipt_cannot_advance_again(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $this->receive($f, $manifest, $item);
        $receivedAt = $item->fresh()->received_at->toISOString();

        try {
            app(ManifestService::class)->receive($manifest, $f['a']['provider'], $item, 'replayed-token');
            $this->fail('A duplicate receipt must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(1, $f['route']->fresh()->current_leg_index);
            $this->assertSame($receivedAt, $item->fresh()->received_at->toISOString());
            $this->assertNull($f['route']->fresh()->route_completed_at);
        }
    }

    public function test_duplicate_cross_provider_receipt_cannot_advance_again(): void
    {
        $f = $this->fixture(crossProvider: true);
        $handoff = $this->handoff($f);
        $service = app(ParcelHandoffService::class);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);

        try {
            $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);
            $this->fail('A duplicate receipt must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(1, $f['route']->fresh()->current_leg_index);
            $this->assertNull($f['route']->fresh()->route_completed_at);
        }
    }

    public function test_wrong_destination_rolls_back_receipt_and_custody(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f, destination: $f['destination']);

        try {
            $this->receive($f, $manifest, $item);
            $this->fail('A receipt outside the expected leg must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route', $exception->errors());
            $this->assertNull($item->fresh()->received_at);
            $this->assertNull($item->fresh()->released_at);
            $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        }
    }

    public function test_wrong_provider_cannot_receive_or_advance(): void
    {
        $f = $this->fixture(crossProvider: true);
        $handoff = $this->handoff($f);

        try {
            app(ParcelHandoffService::class)->receive($handoff, $f['a']['provider'], 'forged-token');
            $this->fail('Only the destination provider may receive.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
            $this->assertSame(ParcelHandoffStatus::InTransit, $handoff->fresh()->status);
        }
    }

    public function test_unaccepted_handoff_cannot_advance(): void
    {
        $f = $this->fixture(crossProvider: true);
        $service = app(ParcelHandoffService::class);
        $handoff = $service->request($f['parcel'], $f['a']['provider']);

        try {
            $service->receive($handoff, $f['b']['provider'], 'forged-token');
            $this->fail('An unaccepted handoff cannot be received.');
        } catch (ValidationException) {
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
            $this->assertSame(ParcelHandoffStatus::Requested, $handoff->fresh()->status);
            $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        }
    }

    public function test_custody_with_wrong_provider_cannot_advance_even_with_receipt(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $item->markReceived('logistics', $f['a']['provider']->id);
        $unrelated = LogisticsProvider::factory()->create();
        $f['parcel']->forceFill(['custody_hub_id' => $f['middle']->id, 'custody_provider_id' => $unrelated->id])->save();

        try {
            app(ParcelLegService::class)->advanceToNextLeg($f['parcel']);
            $this->fail('Receipt cannot override wrong-provider custody.');
        } catch (ValidationException) {
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
            $this->assertNull(app(RouteExecutionService::class)->currentLocation($f['route']));
        }
    }

    public function test_accepted_but_undispatched_handoff_cannot_be_received(): void
    {
        $f = $this->fixture(crossProvider: true);
        $handoff = $this->handoff($f, dispatch: false);

        try {
            app(ParcelHandoffService::class)->receive($handoff, $f['b']['provider'], 'forged-token');
            $this->fail('An undispatched handoff cannot be received.');
        } catch (ValidationException) {
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
            $this->assertSame(ParcelHandoffStatus::Accepted, $handoff->fresh()->status);
        }
    }

    public function test_receiving_rechecks_custody_after_scan_before_recording_receipt(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $service = app(ManifestService::class);
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $f['parcel']->forceFill(['custody_provider_id' => null, 'custody_hub_id' => null])->save();

        try {
            $service->receive($manifest, $f['a']['provider'], $item, $scan['verification_token']);
            $this->fail('A scan cannot restore missing custody.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route', $exception->errors());
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        }
    }

    public function test_receipt_without_matching_custody_cannot_advance(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $item->markReceived('logistics', $f['a']['provider']->id);

        try {
            app(ParcelLegService::class)->advanceToNextLeg($f['parcel']);
            $this->fail('Receipt history without custody must not advance.');
        } catch (ValidationException) {
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
            $this->assertSame($f['a']['station']->id, app(RouteExecutionService::class)->currentLocation($f['route'])->id);
        }
    }

    public function test_final_manifest_receipt_completes_route_without_skipping_sorting(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $this->receive($f, $manifest, $item);
        [$last, $lastItem] = $this->manifest($f, $f['middle'], $f['destination']);

        $this->receive($f, $last, $lastItem);

        $execution = app(RouteExecutionService::class);
        $this->assertNotNull($f['route']->fresh()->route_completed_at);
        $this->assertSame($f['destination']->id, $execution->currentLocation($f['route'])->id);
        $this->assertNull($execution->nextDestination($f['route']));
        $this->assertNull($execution->currentTransportMode($f['route']));
        $this->assertTrue($execution->isAtFinalDestination($f['route']));
        $this->assertSame(OrderStatus::AtSortingCenter, $f['parcel']->fresh()->status);
        $this->assertSame('not_eligible', app(CourierAssignmentService::class)->dispatch($f['parcel']->fresh(), 'delivery')['status']);
    }

    public function test_final_cross_provider_completion_enables_existing_delivery_after_sorting(): void
    {
        $f = $this->fixture(crossProvider: true, singleLeg: true);
        $rider = Rider::factory()->create(['logistics_provider_id' => $f['b']['provider']->id, 'station_hub_id' => $f['destination']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $f['b']['provider']->id, 'rider_id' => $rider->id, 'area_id' => $f['b']['area']->id]);
        $handoff = $this->handoff($f);
        $service = app(ParcelHandoffService::class);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);
        $this->assertNull($f['parcel']->fresh()->delivery_courier_id);
        $this->assertTrue(app(CourierAssignmentService::class)->deliveryReadyAtStation($f['parcel']->fresh(), $f['b']['provider']->id));
        $verify = app(ParcelHandoffVerification::class);
        $token = $verify->issueForLogistics($f['b']['provider'], $f['parcel']->fresh(), ParcelHandoffVerification::ACTION_SORT, stationHubId: $f['destination']->id);

        $this->actingAs($f['b']['provider'], 'logistics')->post(route('logistics.parcel-sorting.sort', $f['parcel']), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($f['route']->fresh()->route_completed_at);
        $this->assertSame(OrderStatus::Sorted, $f['parcel']->fresh()->status);
        $this->assertSame($courier->id, (int) $f['parcel']->fresh()->delivery_courier_id);
        $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->carrier_provider_id);
    }

    public function test_inactive_future_connection_does_not_block_historical_receipt_but_blocks_next_dispatch(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $f['second']->update(['is_active' => false]);

        $this->receive($f, $manifest, $item);

        $this->assertNotNull($item->fresh()->received_at);
        $this->assertSame(1, $f['route']->fresh()->current_leg_index);
        try {
            $this->manifest($f, $f['middle'], $f['destination']);
            $this->fail('The next departure must require an active connection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route', $exception->errors());
            $this->assertSame(1, $f['route']->fresh()->current_leg_index);
            $this->assertNotNull($item->fresh()->received_at);
        }
    }

    public function test_failed_route_update_rolls_back_receipt_and_can_retry_same_token(): void
    {
        $f = $this->fixture();
        [$manifest, $item] = $this->manifest($f);
        $service = app(ManifestService::class);
        $scan = $service->verifyScan($manifest, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        Event::listen('eloquent.updating: '.ParcelRoute::class, function (): void {
            throw new RuntimeException('Route write unavailable');
        });
        try {
            $service->receive($manifest, $f['a']['provider'], $item, $scan['verification_token']);
            $this->fail('The failed route write must abort receiving.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Route write unavailable', $exception->getMessage());
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
            $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        } finally {
            Event::forget('eloquent.updating: '.ParcelRoute::class);
        }

        $service->receive($manifest, $f['a']['provider'], $item, $scan['verification_token']);
        $this->assertSame(1, $f['route']->fresh()->current_leg_index);
    }

    public function test_zero_leg_route_completes_only_after_existing_verified_origin_receipt(): void
    {
        $f = $this->fixture();
        $f['route']->update(['legs' => [], 'destination_hub_id' => $f['a']['station']->id]);
        $f['parcel']->statusHistories()->delete();
        $f['parcel']->forceFill(['status' => OrderStatus::PickedUp, 'custody_provider_id' => null, 'custody_hub_id' => null])->save();
        $this->assertNull(app(RouteExecutionService::class)->currentLocation($f['route']));
        $verify = app(ParcelHandoffVerification::class);
        $token = $verify->issueForLogistics($f['a']['provider'], $f['parcel']->fresh(), ParcelHandoffVerification::ACTION_STATION_RECEIPT, stationHubId: $f['a']['station']->id);

        $this->actingAs($f['a']['provider'], 'logistics')->post(route('logistics.incoming-parcels.toSortingCenter', $f['parcel']), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($f['route']->fresh()->route_completed_at);
        $this->assertTrue(app(RouteExecutionService::class)->isAtFinalDestination($f['route']));
        $this->assertSame([], $f['route']->fresh()->legs);
    }

    public function test_deactivated_current_connection_can_still_be_received_after_dispatch(): void
    {
        $f = $this->fixture(singleLeg: true);
        [$manifest, $item] = $this->manifest($f);
        $f['first']->update(['is_active' => false]);

        $this->receive($f, $manifest, $item);

        $this->assertNotNull($item->fresh()->received_at);
        $this->assertNotNull($f['route']->fresh()->route_completed_at);
        $this->assertSame($f['destination']->id, $f['parcel']->fresh()->custody_hub_id);
        $this->assertTrue(app(RouteExecutionService::class)->validateRouteConnectivity($f['route']));
    }

    public function test_failed_cross_provider_progression_rolls_back_handoff_and_custody(): void
    {
        $f = $this->fixture(crossProvider: true);
        $handoff = $this->handoff($f);
        $service = app(ParcelHandoffService::class);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        Event::listen('eloquent.updating: '.ParcelRoute::class, function (): void {
            throw new RuntimeException('Route write unavailable');
        });
        try {
            $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);
            $this->fail('Progression failure must roll back the handoff receipt.');
        } catch (RuntimeException) {
            $this->assertSame(ParcelHandoffStatus::InTransit, $handoff->fresh()->status);
            $this->assertNull($handoff->fresh()->received_at);
            $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
            $this->assertSame($f['a']['station']->id, $f['parcel']->fresh()->custody_hub_id);
            $this->assertSame(0, $f['route']->fresh()->current_leg_index);
        } finally {
            Event::forget('eloquent.updating: '.ParcelRoute::class);
        }

        $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);
        $this->assertSame(1, $f['route']->fresh()->current_leg_index);
    }

    public function test_unverified_custody_field_does_not_report_a_planned_hub_as_location(): void
    {
        $f = $this->fixture();
        $f['parcel']->forceFill(['custody_hub_id' => $f['middle']->id])->save();

        $this->assertNull(app(RouteExecutionService::class)->currentLocation($f['route']));
        $this->assertFalse(app(RouteExecutionService::class)->isAtFinalDestination($f['route']));
    }

    public function test_legacy_parcel_without_saved_route_keeps_existing_receiving_behavior(): void
    {
        $f = $this->fixture();
        $f['route']->delete();
        [$manifest, $item] = $this->manifest($f);

        $this->receive($f, $manifest, $item);

        $this->assertSame($f['middle']->id, $f['parcel']->fresh()->custody_hub_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $f['parcel']->fresh()->status);
        $this->assertDatabaseCount('parcel_routes', 0);
    }
}
