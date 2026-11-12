<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Rider\RiderRegistrationService;
use Illuminate\Validation\ValidationException;

class WorkerCustodyRecoveryTest extends RiderTestCase
{
    public function test_suspended_rider_without_handover_does_not_open_a_custody_case(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, ['courier_id' => $this->courier->id], [
            'carrier_provider_id' => $this->provider->id,
            'origin_station_hub_id' => $this->rider->station_hub_id,
        ]);
        app(RiderRegistrationService::class)->suspend($this->rider, $this->provider, 'Unavailable before physical pickup.');
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.exceptions.store', $parcel), [
            'type' => 'worker_unavailable', 'reason' => 'The Rider is unavailable.', 'recovery_owner' => 'Station supervisor',
        ])->assertSessionHasErrors('type');
        $this->assertFalse($parcel->incidentSet()->exists());
    }

    public function test_pickup_parcel_requires_actual_station_receipt_before_recovery_closes(): void
    {
        $station = $this->rider->station_hub_id;
        $parcel = $this->parcel(OrderStatus::PickedUp, ['courier_id' => $this->courier->id], [
            'carrier_provider_id' => $this->provider->id,
            'origin_station_hub_id' => $station,
            'handover_at' => now(),
        ]);
        app(RiderRegistrationService::class)->suspend($this->rider, $this->provider, 'Unavailable after physical pickup.');
        $this->actingAs($this->rider->fresh(), 'rider')->get(route('rider.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]));
        $service = app(ParcelIncidentService::class);
        $case = $service->reportOperational($parcel, $this->provider, [
            'type' => 'worker_unavailable', 'reason' => 'The Rider cannot complete the pickup run.',
            'recovery_owner' => 'Station supervisor',
        ]);
        $service->recordWorkerContact($case, $this->provider, 'Called the assigned Rider.');
        $this->assertNull($parcel->fresh()->custody_hub_id);
        $this->assertSame($this->courier->id, $case->custody_snapshot['worker_id']);
        try {
            $service->finishWorkerRecovery($case, $this->provider);
            $this->fail('A case cannot close without station receipt.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('receipt', $error->errors());
        }
        $this->actingAs(LogisticsProvider::factory()->create(['status' => 'approved']), 'logistics')
            ->post(route('logistics.exceptions.worker-recovery', $case), ['action' => 'verify', 'tracking_number' => $parcel->tracking_number])
            ->assertForbidden();
        $this->withoutVite();
        $detail = route('logistics.exceptions.show', $case);
        $action = route('logistics.exceptions.worker-recovery', $case);
        $this->actingAs($this->provider, 'logistics')->get($detail)->assertOk()->assertSee('Verify parcel for station receipt');
        $this->from($detail)->post($action, ['action' => 'verify', 'tracking_number' => 'UNRELATED'])
            ->assertSessionHasErrors('tracking_number');
        $this->from($detail)->post($action, ['action' => 'verify', 'tracking_number' => $parcel->tracking_number])
            ->assertRedirect($detail)->assertSessionHasNoErrors()->assertSessionHas('worker_receipt_token');
        $token = session('worker_receipt_token.token');
        $this->from($detail)->post($action, ['action' => 'receive', 'verification_token' => $token])
            ->assertSessionHasErrors('physically_received');
        $this->assertNull($parcel->fresh()->custody_hub_id);
        $this->from($detail)->post($action, ['action' => 'receive', 'verification_token' => $token, 'physically_received' => '1'])
            ->assertRedirect($detail)->assertSessionHasNoErrors();
        $service->receiveWorkerParcel($case, $this->provider, $token);
        $this->assertSame(1, $case->events()->where('event', 'worker_station_received')->count());
        $this->assertTrue($case->fresh()->isActive());
        $service->finishWorkerRecovery($case, $this->provider);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertSame($station, $parcel->fresh()->custody_hub_id);
        $this->assertFalse($case->fresh()->isActive());
    }

    public function test_delivery_recovery_keeps_verified_receipt_when_no_replacement_is_available(): void
    {
        $station = $this->rider->station_hub_id;
        $parcel = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id], [
            'carrier_provider_id' => $this->provider->id,
            'destination_area_id' => $this->courier->area_id,
            'custody_provider_id' => $this->provider->id,
        ]);
        $service = app(ParcelIncidentService::class);
        $case = $service->reportOperational($parcel, $this->provider, [
            'type' => 'worker_unavailable', 'reason' => 'The assigned Rider cannot be reached.',
            'recovery_owner' => 'Station supervisor',
        ]);
        $this->assertNull($parcel->fresh()->custody_hub_id);
        $service->recordWorkerContact($case, $this->provider, 'No response after two calls.', escalate: true);
        $this->assertTrue($case->events()->where('event', 'recovery_escalated')->exists());
        $proof = $service->verifyWorkerStationReceipt($case, $this->provider, $parcel->tracking_number);
        $service->receiveWorkerParcel($case, $this->provider, $proof['token']);
        try {
            $service->finishWorkerRecovery($case, $this->provider);
            $this->fail('The original assignment cannot be released without an eligible replacement.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('eligible replacement Rider', $error->errors()['receipt'][0]);
        }
        $this->assertTrue($case->fresh()->isActive());
        $this->assertSame($station, $parcel->fresh()->custody_hub_id);
        $this->assertSame($this->courier->id, $parcel->fresh()->delivery_courier_id);
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $replacement = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $service->finishWorkerRecovery($case, $this->provider);
        $service->finishWorkerRecovery($case, $this->provider);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertSame($replacement->id, $parcel->fresh()->delivery_courier_id);
        $this->assertFalse($case->fresh()->isActive());
        $this->assertSame(1, $case->events()->where('event', 'resolved')->count());
    }

    public function test_failed_delivery_recovery_records_return_without_inventing_an_attempt(): void
    {
        $parcel = $this->parcel(OrderStatus::DeliveryFailed, ['delivery_courier_id' => $this->courier->id], [
            'carrier_provider_id' => $this->provider->id,
            'destination_area_id' => $this->courier->area_id,
            'custody_provider_id' => $this->provider->id,
            'delivery_attempts' => 1,
        ]);
        $service = app(ParcelIncidentService::class);
        $data = ['type' => 'worker_unavailable', 'reason' => 'Rider cannot return to the station.', 'recovery_owner' => 'Station supervisor'];
        $case = $service->reportOperational($parcel, $this->provider, $data);
        $proof = $service->verifyWorkerStationReceipt($case, $this->provider, $parcel->tracking_number);
        $service->receiveWorkerParcel($case, $this->provider, $proof['token']);
        $this->assertSame($case->id, $service->reportOperational($parcel, $this->provider, $data)->id);
        $service->finishWorkerRecovery($case, $this->provider);
        $parcel->refresh();
        $this->assertNotNull($parcel->returned_to_station_at);
        $this->assertSame($this->rider->station_hub_id, $parcel->custody_hub_id);
        $this->assertSame(OrderStatus::DeliveryFailed, $parcel->status);
        $this->assertSame(1, $parcel->delivery_attempts);
        $this->assertFalse($case->fresh()->isActive());
    }
}
