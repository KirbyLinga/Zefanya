<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Incident\IncidentReported;
use App\Notifications\Incident\IncidentResolved;
use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class ParcelIncidentReportingTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->courier = $this->assignableCourier($this->provider);
    }

    private function parcel(): SellerOrder
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::PickedUp)->create(['courier_id' => $this->courier->id]);
        $parcel->forceFill(['handover_at' => now()])->save();
        StatusHistory::record($parcel, OrderStatus::ReadyForPickup, OrderStatus::PickedUp,
            actor: OrderActor::Rider, actorId: $this->courier->id, source: 'web');

        return $parcel->fresh();
    }

    private function payload(string $type = 'lost'): array
    {
        return ['type' => $type, 'reason' => 'Parcel could not be accounted for.', 'description' => 'Checked the pickup vehicle and hub receiving register.'];
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('damage.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII='));
    }

    public function test_logistics_can_report_lost_without_a_photo_and_buyer_and_seller_are_notified(): void
    {
        $parcel = $this->parcel();
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.delivery-monitoring.show', $parcel))
            ->assertOk()->assertSee('Report lost / damaged')->assertSee('Photo (required for Damaged)');
        $this->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $incident = Incidents::sole();
        $this->assertSame('lost', $incident->type);
        $this->assertNull($incident->photo_path);
        $this->assertSame('logistics_provider', $incident->reporter_type);
        $this->assertSame($this->provider->id, $incident->reporter_id);
        $this->assertSame(ParcelIncidentStatus::Open, $incident->status);
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertNotEmpty($incident->custody_snapshot);
        foreach ([$parcel->order->buyer, $parcel->seller, $this->provider] as $recipient) {
            Notification::assertSentTo($recipient, IncidentReported::class, fn ($notice) => $notice->incident->is($incident));
        }
    }

    public function test_damaged_photo_is_required_in_http_and_direct_service_calls(): void
    {
        $parcel = $this->parcel();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel), $this->payload('damaged'))
            ->assertSessionHasErrors(['photo' => 'Attach a photo when reporting a damaged parcel.']);
        try {
            app(ParcelIncidentService::class)->report($parcel, $this->provider, $this->payload('damaged'));
            $this->fail('Damaged reports require evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('photo', $exception->errors());
        }
        $this->assertSame(0, Incidents::count());
        Notification::assertNothingSent();
    }

    public function test_damage_photo_is_stored_privately_and_notifications_have_no_private_path(): void
    {
        $parcel = $this->parcel();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel),
            $this->payload('damaged') + ['photo' => $this->photo()])->assertSessionHasNoErrors();
        $incident = Incidents::sole();
        $this->assertSame('local', $incident->photo_disk);
        Storage::disk('local')->assertExists($incident->photo_path);
        Storage::disk('public')->assertMissing($incident->photo_path);
        foreach ([$parcel->order->buyer, $parcel->seller] as $recipient) {
            Notification::assertSentTo($recipient, IncidentReported::class, function ($notice) use ($incident, $recipient): bool {
                $payload = $notice->toArray($recipient);

                return $notice->incident->is($incident) && $payload['incident_type'] === 'damaged'
                    && ! isset($payload['photo_path']);
            });
        }
    }

    public function test_non_images_and_oversized_photos_are_rejected(): void
    {
        $parcel = $this->parcel();
        foreach ([UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('oversized.png', 5121, 'image/png')] as $photo) {
            $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel),
                $this->payload('damaged') + ['photo' => $photo])->assertSessionHasErrors('photo');
        }
        $this->assertSame(0, Incidents::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_incident_routes_reject_guests_and_logistics_cannot_call_the_rider_route(): void
    {
        $parcel = $this->parcel();
        $this->postJson(route('parcels.incidents.store', $parcel), $this->payload())->assertUnauthorized();
        $this->postJson(route('logistics.parcels.incidents.store', $parcel), $this->payload())->assertUnauthorized();
        $this->actingAs($this->provider, 'logistics')->postJson(route('parcels.incidents.store', $parcel), $this->payload())
            ->assertUnauthorized();
        $this->assertSame(0, Incidents::count());
    }

    public function test_operational_assigned_rider_can_report_but_suspended_rider_cannot(): void
    {
        $parcel = $this->parcel();
        $rider = $this->courier->rider;
        $this->actingAs($rider, 'rider')->postJson(route('parcels.incidents.store', $parcel), $this->payload())
            ->assertCreated()->assertJsonPath('data.type', 'lost');
        $rider->forceFill(['status' => 'suspended'])->save();
        $otherParcel = $this->parcel();
        $this->actingAs($rider->fresh(), 'rider')->postJson(route('parcels.incidents.store', $otherParcel), $this->payload())
            ->assertRedirect(route('home', ['login' => 1]));
        $this->assertGuest('rider');
        $this->assertSame(1, Incidents::count());
    }

    public function test_foreign_provider_and_spoofed_reporter_cannot_change_incident_ownership(): void
    {
        $parcel = $this->parcel();
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())->assertForbidden();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel),
            $this->payload() + ['reporter_id' => $other->id, 'reporter_type' => 'rider'])->assertSessionHasNoErrors();
        $this->assertSame($this->provider->id, Incidents::sole()->reporter_id);
        $this->assertSame('logistics_provider', Incidents::sole()->reporter_type);
    }

    public function test_pre_pickup_terminal_and_duplicate_reports_are_blocked(): void
    {
        foreach ([OrderStatus::ReadyForPickup, OrderStatus::Completed] as $status) {
            $parcel = $this->parcel();
            $parcel->forceFill(['status' => $status])->save();
            $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())
                ->assertSessionHasErrors('parcel');
        }
        $parcel = $this->parcel();
        $this->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())->assertSessionHasErrors('parcel');
        $this->assertSame(1, Incidents::count());
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_admin_can_resume_an_incident_but_operations_stay_blocked_until_resolution(): void
    {
        $parcel = $this->parcel();
        $service = app(ParcelIncidentService::class);
        $incident = $service->report($parcel, $this->provider, $this->payload());
        try {
            ParcelIncidentService::assertOperationsAllowed($parcel);
            $this->fail('Open incidents must block operations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parcel', $exception->errors());
        }
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->post(route('admin.incidents.resolve', $incident), [
            'resolution' => 'RESUME', 'resolution_note' => 'Parcel located and its packaging checked.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->fresh()->status);
        ParcelIncidentService::assertOperationsAllowed($parcel->fresh());
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertSame($admin->id, (int) $incident->events()->where('event', 'resolved')->sole()->resolved_by_admin_id);
        Notification::assertSentTo($parcel->order->buyer, IncidentResolved::class);
        Notification::assertSentTo($parcel->seller, IncidentResolved::class);
    }

    public function test_handover_timestamp_without_verified_pickup_history_does_not_allow_reporting(): void
    {
        $parcel = $this->parcel();
        StatusHistory::where('seller_order_id', $parcel->id)->delete();
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.parcels.incidents.store', $parcel), $this->payload())
            ->assertSessionHasErrors('parcel');
        $this->assertSame(0, Incidents::count());
        Notification::assertNothingSent();
    }

    public function test_photo_is_cleaned_up_when_the_incident_transaction_fails(): void
    {
        $parcel = $this->parcel();
        Event::listen('eloquent.creating: '.Incidents::class, function (): void {
            throw new \RuntimeException('Incident storage unavailable');
        });
        try {
            app(ParcelIncidentService::class)->report($parcel, $this->provider, $this->payload('damaged') + ['photo' => $this->photo()]);
            $this->fail('Report storage failure must be surfaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Incident storage unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.Incidents::class);
        }
        $this->assertSame(0, Incidents::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }
}
