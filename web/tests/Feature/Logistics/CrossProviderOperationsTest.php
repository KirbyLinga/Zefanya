<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\Support\ProvidesDeliveryOtp;
use Tests\TestCase;

class CrossProviderOperationsTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;
    use ProvidesDeliveryOtp;

    private function routeFixture(): array
    {
        $admin = Admin::factory()->create();
        $a = $this->operationalHandoffProvider('Laguna Provider A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal', '040000000', 'CALABARZON');
        $b = $this->operationalHandoffProvider('Quezon Provider B', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I', '040000000', 'CALABARZON');
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-LAGUNA-QUEZON');
        $pickup = $this->courierAt($a);
        $parcel->forceFill(['courier_id' => $pickup->id, 'origin_station_hub_id' => $a['station']->id])->save();
        $this->activeHandoffLink($a['provider'], $b['provider'], $admin);
        $service = app(ParcelHandoffService::class);
        $handoff = $service->request($parcel, $a['provider']);

        return compact('admin', 'a', 'b', 'seller', 'buyer', 'parcel', 'pickup', 'service', 'handoff');
    }

    private function courierAt(array $network): Courier
    {
        $rider = Rider::factory()->create([
            'logistics_provider_id' => $network['provider']->id,
            'station_hub_id' => $network['station']->id,
            'status' => 'approved', 'email_verified_at' => now(),
        ]);

        return Courier::factory()->create([
            'logistics_provider_id' => $network['provider']->id,
            'rider_id' => $rider->id, 'area_id' => $network['area']->id,
            'is_available' => true, 'max_weight_grams' => 10000,
        ]);
    }

    private function receive(array $f): void
    {
        $service = $f['service'];
        $handoff = $service->accept($f['handoff'], $f['b']['provider']);
        $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $f['a']['provider'], $scan['verification_token']);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($handoff, $f['b']['provider'], $scan['verification_token']);
    }

    public function test_laguna_to_quezon_verified_receipt_allows_sort_assign_and_cod_delivery(): void
    {
        Storage::fake('local');
        $f = $this->routeFixture();
        $this->receive($f);
        $parcel = $f['parcel']->fresh();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 44900]);
        $courier = $this->courierAt($f['b']);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertSame($f['a']['provider']->id, $parcel->carrier_provider_id);
        $this->assertSame($f['b']['provider']->id, $parcel->custody_provider_id);

        $this->actingAs($f['b']['provider'], 'logistics')
            ->get(route('logistics.parcel-sorting.index'))->assertOk()->assertSee('#'.$parcel->id);
        $scan = $this->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->assertJsonPath('context', 'sorting')->json();
        $this->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $scan['actions'][0]['verification_token']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
        $this->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $courier->id])->assertRedirect();
        $this->assertSame($courier->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($parcel->fresh(), $f['b']['provider']));
        $this->assertSame(1, $parcel->deliveryAssignments()->where('role', 'delivery')->count());
        $this->get(route('logistics.delivery-assignments.index'))->assertOk()->assertSee('#'.$parcel->id);
        $this->get(route('logistics.delivery-monitoring.index'))->assertOk()->assertSee('#'.$parcel->id);
        $replacement = $this->courierAt($f['b']);
        $this->assertSame($replacement->id, app(CourierAssignmentService::class)->reassign($parcel->fresh(), $f['b']['provider'], 'delivery', $replacement->id, 'Rider unavailable')?->id);
        $courier = $replacement;
        $operations = app(RiderOperationsService::class);
        $operations->startDelivery($courier->rider, $parcel->fresh());
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $operations->completeDelivery($courier->rider, $parcel->fresh(), [
            'delivery_otp' => $this->deliveryOtp($parcel->fresh()),
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'received_by' => 'Buyer', 'cod_amount_minor' => 44900,
        ]);
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(44900, (int) $payment->fresh()->collected_amount_minor);
        $this->assertSame('TRK-LAGUNA-QUEZON', $parcel->fresh()->tracking_number);
        $this->assertNotNull($parcel->fresh()->deliveryProof);
        $titles = array_column($parcel->fresh()->trackingEvents(), 'title');
        $this->assertSame('Delivered', $titles[array_key_last($titles)]);
        $this->assertLessThan(array_search('Delivered', $titles), array_search('Received by destination provider', $titles));
    }

    public function test_acceptance_and_dispatch_never_authorize_destination_operations(): void
    {
        $f = $this->routeFixture();
        $service = $f['service'];
        $handoff = $service->accept($f['handoff'], $f['b']['provider']);
        foreach (['accepted', 'in_transit'] as $phase) {
            if ($phase === 'in_transit') {
                $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'dispatch');
                $service->dispatch($handoff, $f['a']['provider'], $scan['verification_token']);
            }
            $this->assertFalse(Gate::forUser($f['b']['provider'])->allows('advance-shipment', $f['parcel']->fresh()));
            $this->actingAs($f['b']['provider'], 'logistics')->post(route('logistics.parcel-sorting.sort', $f['parcel']))->assertForbidden();
            $this->assertNull($f['parcel']->fresh()->custody_provider_id);
        }
    }

    public function test_origin_loses_backend_control_but_keeps_handoff_history_after_receipt(): void
    {
        $f = $this->routeFixture();
        $this->receive($f);
        $parcel = $f['parcel']->fresh();
        $this->actingAs($f['a']['provider'], 'logistics')->get(route('logistics.handoffs.show', $f['handoff']))->assertOk()->assertSee('Quezon Provider B');
        $this->get(route('logistics.parcel-sorting.index'))->assertOk()->assertDontSee('#'.$parcel->id.'<', false);
        $this->post(route('logistics.parcel-sorting.sort', $parcel))->assertForbidden();
        $this->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])->assertForbidden();
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($parcel, $f['a']['provider']));
        $this->expectException(\InvalidArgumentException::class);
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Sorted, actor: OrderActor::Logistics, actorId: $f['a']['provider']->id);
    }

    public function test_destination_can_use_internal_manifests_without_rewriting_the_origin_route(): void
    {
        $f = $this->routeFixture();
        $this->receive($f);
        $service = app(ManifestService::class);
        $manifest = $service->create($f['b']['provider'], $f['b']['station']->id, $f['b']['province']->id);
        $this->assertTrue($service->eligibleParcels($manifest)->contains('id', $f['parcel']->id));
        $item = $service->addParcel($manifest, $f['parcel']->fresh());
        $scan = $service->verifyScan($manifest, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['b']['provider'], [$item->id => $scan['verification_token']]);
        $scan = $service->verifyScan($manifest->fresh(), $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($manifest->fresh(), $f['b']['provider'], $item, $scan['verification_token']);
        $parcel = $f['parcel']->fresh();
        $this->assertSame($f['b']['province']->id, $service->currentHubId($parcel));
        $this->assertSame($f['a']['station']->id, (int) $parcel->origin_station_hub_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertSame($f['a']['provider']->id, $parcel->carrier_provider_id);
    }

    public function test_tracking_shows_chronological_handoffs_without_private_actor_or_notes(): void
    {
        $f = $this->routeFixture();
        $this->receive($f);
        $parcel = $f['parcel']->fresh();
        $events = $parcel->trackingEvents();
        $this->assertSame(['Order created', 'Handoff requested', 'Handoff accepted', 'Dispatched to destination provider', 'Received by destination provider'], array_column($events, 'title'));
        $this->assertCount(5, array_unique(array_column($events, 'key')));
        foreach ([['buyer', $f['buyer'], route('buyer.orders.show', $parcel->order)], ['seller', $f['seller'], route('seller.orders.show', $parcel)]] as [$guard, $actor, $url]) {
            $this->actingAs($actor, $guard)->get($url)->assertOk()
                ->assertSeeInOrder(['Handoff requested', 'Handoff accepted', 'Dispatched to destination provider', 'Received by destination provider'])
                ->assertDontSee($f['pickup']->name)->assertDontSee($f['pickup']->phone);
        }
    }

    public function test_admin_exception_resolution_is_audited_and_cannot_transfer_custody(): void
    {
        $f = $this->routeFixture();
        $exception = ParcelHandoffException::query()->create([
            'seller_order_id' => $f['parcel']->id, 'origin_provider_id' => $f['a']['provider']->id,
            'reason' => 'Route requires review', 'status' => ParcelHandoffException::OPEN,
        ]);
        $this->actingAs($f['admin'], 'admin')->post(route('admin.partnerships.exceptions.resolve', $exception), ['resolution_note' => 'Partner route reviewed.'])->assertRedirect();
        $this->assertSame(ParcelHandoffException::RESOLVED, $exception->fresh()->status);
        $this->assertSame($f['admin']->id, (int) $exception->fresh()->resolved_by);
        $this->assertNotNull($exception->fresh()->resolved_at);
        $this->assertNull($f['parcel']->fresh()->custody_provider_id);
        $this->get(route('admin.partnerships.index'))->assertOk()->assertSee('Partner route reviewed.')->assertSee('RESOLVED');
        $support = Admin::factory()->create(['role' => 'support']);
        $this->actingAs($support, 'admin')->post(route('admin.partnerships.exceptions.resolve', $exception), ['resolution_note' => 'Forged'])->assertForbidden();
        $this->assertSame('Partner route reviewed.', $exception->fresh()->resolution_note);
    }

    public function test_transferred_parcels_cannot_bypass_actor_authorization_with_a_null_actor(): void
    {
        $f = $this->routeFixture();
        $this->receive($f);
        $this->expectException(\InvalidArgumentException::class);
        app(SellerOrderStatusService::class)->transition($f['parcel']->fresh(), OrderStatus::Sorted);
    }

    public function test_unrelated_provider_cannot_see_handoffs_or_received_parcel_data(): void
    {
        $f = $this->routeFixture();
        $this->receive($f);
        $other = $this->operationalHandoffProvider('Unrelated', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal')['provider'];
        $this->actingAs($other, 'logistics')->get(route('logistics.handoffs.show', $f['handoff']))->assertNotFound();
        $this->get(route('logistics.handoffs.index'))->assertOk()->assertDontSee('TRK-LAGUNA-QUEZON');
        $this->get(route('logistics.parcel-sorting.index'))->assertOk()->assertDontSee('TRK-LAGUNA-QUEZON');
        $this->postJson(route('logistics.scan.verify'), ['tracking_number' => 'TRK-LAGUNA-QUEZON'])->assertForbidden();
    }
}
