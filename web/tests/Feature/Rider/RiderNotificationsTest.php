<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Rider\Rider;
use App\Notifications\Rider\AccountStatusChanged;
use App\Notifications\Rider\AssignmentReassigned;
use App\Notifications\Rider\DeliveryAssigned;
use App\Notifications\Rider\DeliveryReturned;
use App\Notifications\Rider\PickupAssigned;
use App\Notifications\Rider\StationChanged;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Rider\RiderNotifier;
use App\Services\Rider\RiderOperationsService;
use App\Services\Rider\RiderRegistrationService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;

class RiderNotificationsTest extends RiderTestCase
{
    // ── Assignment notifications ────────────────────────────────────────────

    public function test_pickup_assignment_notifies_only_the_assigned_rider_after_dispatch_confirmation(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $order = $this->parcel(OrderStatus::ReadyForPickup);

        $courier = app(CourierAssignmentService::class)
            ->reservePickup($order, $this->provider, $this->courier->id);

        $this->assertNotNull($courier);
        $this->assertSame($this->courier->id, (int) $courier->id);
        // Reserving a Rider stays silent until Logistics confirms the dispatch.
        Notification::assertNotSentTo($this->rider, PickupAssigned::class);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.confirmPickupDispatch', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo(
            $this->rider,
            PickupAssigned::class,
            fn (PickupAssigned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->assignmentId > 0,
        );
        Notification::assertNotSentTo($otherCourier->rider, PickupAssigned::class);

        $this->actingAsRider()
            ->get(route('rider.pickups.index'))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    public function test_delivery_assignment_notifies_only_the_assigned_rider_and_appears_in_their_list(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $order = $this->parcel(OrderStatus::Sorted, [], ['origin_station_hub_id' => $this->courier->rider->station_hub_id]);

        $courier = app(CourierAssignmentService::class)
            ->assignDelivery($order, $this->provider, $this->courier->id);

        $this->assertNotNull($courier);

        Notification::assertSentTo(
            $this->rider,
            DeliveryAssigned::class,
            fn (DeliveryAssigned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->rescheduled === false,
        );
        Notification::assertNotSentTo($otherCourier->rider, DeliveryAssigned::class);

        $this->actingAsRider()
            ->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    public function test_reassignment_notifies_both_riders_and_moves_the_task_and_dashboard_count(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $otherRider = $otherCourier->rider;

        $order = $this->parcel(OrderStatus::DeliveryFailed, [
            'delivery_courier_id' => $this->courier->id,
            'delivery_attempts' => 1, 'origin_station_hub_id' => $this->courier->rider->station_hub_id,
        ]);

        // The Logistics reschedule flow frees the slot before assigning again.
        $order->forceFill(['delivery_courier_id' => null])->save();

        $assigned = app(CourierAssignmentService::class)->assignDelivery(
            $order->fresh(),
            $this->provider,
            $otherCourier->id,
            null,
            true,
            $this->courier,
        );

        $this->assertNotNull($assigned);
        $this->assertSame($otherCourier->id, (int) $assigned->id);

        Notification::assertSentTo(
            $otherRider,
            DeliveryAssigned::class,
            fn (DeliveryAssigned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->rescheduled === true,
        );
        Notification::assertSentTo(
            $this->rider,
            AssignmentReassigned::class,
            fn (AssignmentReassigned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->role === 'delivery',
        );

        $operations = app(RiderOperationsService::class);
        $this->assertSame(0, $operations->dashboard($this->rider)['active_deliveries']);
        $this->assertSame(1, $operations->dashboard($otherRider)['active_deliveries']);

        $this->actingAsRider($this->rider)
            ->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertDontSee($order->tracking_number);

        $this->actingAsRider($otherRider)
            ->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    public function test_logistics_reschedule_notifies_the_new_rider_and_the_displaced_rider(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $otherRider = $otherCourier->rider;

        // Keep this courier out of the candidate pool by capacity so the
        // dispatcher has to pick the second rider.
        $this->courier->forceFill(['max_weight_grams' => 400])->save();

        $order = $this->parcel(OrderStatus::DeliveryFailed, [
            'delivery_courier_id' => $this->courier->id,
            'delivery_attempts' => 1, 'origin_station_hub_id' => $this->courier->rider->station_hub_id,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.reschedule', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($otherCourier->id, (int) $order->fresh()->delivery_courier_id);

        Notification::assertSentTo(
            $otherRider,
            DeliveryAssigned::class,
            fn (DeliveryAssigned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->rescheduled === true,
        );
        Notification::assertSentTo(
            $this->rider,
            AssignmentReassigned::class,
            fn (AssignmentReassigned $notification): bool => $notification->sellerOrder->is($order),
        );
    }

    public function test_a_failed_reschedule_without_a_replacement_keeps_the_task_and_sends_no_notification(): void
    {
        $this->courier->forceFill(['max_weight_grams' => 400])->save();

        $order = $this->parcel(OrderStatus::DeliveryFailed, [
            'delivery_courier_id' => $this->courier->id,
            'delivery_attempts' => 1, 'origin_station_hub_id' => $this->courier->rider->station_hub_id,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.reschedule', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        // No replacement committed, so the failed task stays with its rider
        // instead of silently vanishing from their list.
        $this->assertSame($this->courier->id, (int) $order->fresh()->delivery_courier_id);
        $dashboard = app(RiderOperationsService::class)->dashboard($this->rider);
        $this->assertSame(0, $dashboard['active_deliveries']);
        $this->assertSame(1, $dashboard['needs_attention']);
        Notification::assertNothingSent();

        $this->actingAsRider()
            ->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    public function test_returning_a_failed_delivery_cancels_the_task_and_notifies_the_rider(): void
    {
        $order = $this->parcel(OrderStatus::DeliveryFailed, [
            'delivery_courier_id' => $this->courier->id,
            'delivery_attempts' => 2,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.return', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::DeliveryFailed, $order->fresh()->status);
        $this->assertSame(ParcelReturnStatus::Requested, $order->fresh()->parcelReturn->status);

        Notification::assertSentTo(
            $this->rider,
            DeliveryReturned::class,
            fn (DeliveryReturned $notification): bool => $notification->sellerOrder->is($order)
                && $notification->attempt === 2 && $notification->receiptPending,
        );

        $this->actingAsRider()
            ->get(route('rider.deliveries.index'))
            ->assertOk()
            ->assertDontSee($order->tracking_number);

        $this->actingAsRider()
            ->get(route('rider.history.index'))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    public function test_a_retried_dispatch_confirmation_does_not_create_a_second_notification(): void
    {
        $order = $this->parcel(OrderStatus::ReadyForPickup);

        $this->assertNotNull(app(CourierAssignmentService::class)
            ->reservePickup($order, $this->provider, $this->courier->id));

        $url = route('logistics.incoming-parcels.confirmPickupDispatch', $order);
        $this->actingAs($this->provider, 'logistics')->post($url)->assertSessionHas('success');
        $this->post($url)->assertSessionHas('success');

        Notification::assertSentToTimes($this->rider, PickupAssigned::class, 1);
    }

    public function test_notification_dedupe_key_suppresses_a_replayed_event(): void
    {
        $order = $this->parcel(OrderStatus::DeliveryFailed);

        $this->fakeNotification($this->rider, [
            'type' => DeliveryAssigned::class,
            'event_key' => 'delivery-assigned:999',
            'title' => 'Already delivered',
            'message' => 'Existing notification',
            'url' => route('rider.deliveries.index'),
        ]);

        app(RiderNotifier::class)->send($this->rider, new DeliveryAssigned($order, 999));

        Notification::assertNotSentTo($this->rider, DeliveryAssigned::class);
        $this->assertSame(1, $this->rider->notifications()->count());
    }

    public function test_a_failed_assignment_transaction_does_not_dispatch_a_notification(): void
    {
        $order = $this->parcel(OrderStatus::ReadyForPickup);

        $status = Mockery::mock(SellerOrderStatusService::class);
        $status->shouldReceive('transition')->once()->andThrow(new \RuntimeException('boom'));

        $service = new CourierAssignmentService($status);

        try {
            $service->assignPickup($order, $this->provider, $this->courier->id);
            $this->fail('The status transition should have thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNull($order->fresh()->courier_id);
        $this->assertDatabaseCount('delivery_assignments', 0);
        Notification::assertNotSentTo($this->rider, PickupAssigned::class);
    }

    // ── Station / account changes ───────────────────────────────────────────

    public function test_station_change_notifies_the_rider_once(): void
    {
        $first = $this->stationForProvider($this->provider);
        $second = $this->secondStation();
        $service = app(RiderRegistrationService::class);

        // Re-selecting the current station is not an event.
        $service->changeStation($this->rider, $this->provider, $first->id);
        Notification::assertNotSentTo($this->rider, StationChanged::class);

        $service->changeStation($this->rider, $this->provider, $second->id);

        Notification::assertSentTo(
            $this->rider,
            StationChanged::class,
            function (StationChanged $notification) use ($first, $second): bool {
                $message = $notification->toArray($this->rider)['message'];

                return $notification->station->is($second)
                    && $notification->previousStationName === $first->name
                    && str_contains($message, $first->name)
                    && str_contains($message, $second->name);
            },
        );

        $service->changeStation($this->rider->fresh(), $this->provider, $second->id);

        Notification::assertSentToTimes($this->rider, StationChanged::class, 1);
    }

    public function test_suspend_deactivate_and_reactivate_notify_the_rider(): void
    {
        $service = app(RiderRegistrationService::class);

        $service->suspend($this->rider, $this->provider, 'Medical leave');

        Notification::assertSentTo(
            $this->rider,
            AccountStatusChanged::class,
            fn (AccountStatusChanged $notification): bool => $notification->status === Rider::STATUS_SUSPENDED
                && $notification->reason === 'Medical leave',
        );

        $service->reactivate($this->rider->fresh(), $this->provider);

        Notification::assertSentTo(
            $this->rider,
            AccountStatusChanged::class,
            fn (AccountStatusChanged $notification): bool => $notification->status === Rider::STATUS_ACTIVE,
        );

        $service->deactivate($this->rider->fresh(), $this->provider, 'Resigned');

        Notification::assertSentTo(
            $this->rider,
            AccountStatusChanged::class,
            fn (AccountStatusChanged $notification): bool => $notification->status === Rider::STATUS_DEACTIVATED
                && $notification->reason === 'Resigned',
        );
    }

    // ── Notification centre ─────────────────────────────────────────────────

    public function test_unread_count_and_recent_endpoints_only_see_the_signed_in_riders_notifications(): void
    {
        $otherRider = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;

        $unread = $this->fakeNotification($this->rider, $this->payload('Unread pickup'));
        $read = $this->fakeNotification($this->rider, $this->payload('Already read'), now());
        $foreign = $this->fakeNotification($otherRider, $this->payload('Other rider only'));

        $this->actingAsRider()
            ->getJson(route('rider.notifications.count'))
            ->assertOk()
            ->assertJson(['count' => 1]);

        $recent = $this->actingAsRider()
            ->getJson(route('rider.notifications.recent'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->json();

        $this->assertCount(2, $recent['notifications']);
        $this->assertNotContains($foreign->id, array_column($recent['notifications'], 'id'));
        $this->assertContains($unread->id, array_column($recent['notifications'], 'id'));
        $this->assertContains($read->id, array_column($recent['notifications'], 'id'));
    }

    public function test_mark_read_mark_all_and_open_are_scoped_to_the_rider(): void
    {
        $unread = $this->fakeNotification($this->rider, $this->payload('Open me'));
        $other = $this->fakeNotification($this->rider, $this->payload('Mark all me'));

        $this->actingAsRider()
            ->post(route('rider.notifications.markRead', $unread->id))
            ->assertRedirect();

        $this->assertNotNull($unread->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);

        $this->actingAsRider()
            ->post(route('rider.notifications.readAll'))
            ->assertRedirect();

        $this->assertSame(0, $this->rider->unreadNotifications()->count());
    }

    public function test_open_marks_the_notification_read_and_redirects_to_its_rider_page(): void
    {
        $notification = $this->fakeNotification($this->rider, $this->payload('Pickup task'));

        $this->actingAsRider()
            ->post(route('rider.notifications.open', $notification->id))
            ->assertRedirect(route('rider.pickups.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_opening_a_stale_assignment_notification_revalidates_the_task_list(): void
    {
        $order = $this->parcel(OrderStatus::ReadyForPickup);

        app(CourierAssignmentService::class)
            ->reservePickup($order, $this->provider, $this->courier->id);

        $notification = $this->fakeNotification($this->rider, [
            'type' => PickupAssigned::class,
            'title' => 'Pickup task',
            'message' => 'A pickup task was assigned to you.',
            'url' => route('rider.pickups.index'),
        ]);

        // Logistics moved the parcel away: the notification is now stale.
        $order->fresh()->forceFill(['courier_id' => null])->save();

        $this->actingAsRider()
            ->post(route('rider.notifications.open', $notification->id))
            ->assertRedirect(route('rider.pickups.index'));

        $this->actingAsRider()
            ->get(route('rider.pickups.index'))
            ->assertOk()
            ->assertSee('No pickup assignments')
            ->assertDontSee($order->tracking_number);
    }

    public function test_open_rejects_a_notification_url_outside_the_rider_panel(): void
    {
        $notification = $this->fakeNotification($this->rider, array_merge(
            $this->payload('Suspicious'),
            ['url' => 'https://example.test/phishing'],
        ));

        $this->actingAsRider()
            ->post(route('rider.notifications.open', $notification->id))
            ->assertRedirect(route('rider.notifications.index'));
    }

    public function test_a_rider_cannot_read_or_open_another_riders_notification(): void
    {
        $otherRider = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $foreign = $this->fakeNotification($otherRider, $this->payload('Private task'));

        $this->actingAsRider()
            ->post(route('rider.notifications.markRead', $foreign->id))
            ->assertNotFound();

        $this->actingAsRider()
            ->post(route('rider.notifications.open', $foreign->id))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_notifications_page_and_bell_render_only_the_signed_in_riders_rows(): void
    {
        $this->fakeNotification($this->rider, $this->payload('My pickup assignment'));

        $otherRider = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $this->fakeNotification($otherRider, $this->payload('Another rider pickup'));

        $this->actingAsRider()
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('rider-bell-btn', false)
            ->assertSee('Notifications, 1 unread')
            ->assertSee('My pickup assignment')
            ->assertDontSee('Another rider pickup');

        $this->actingAsRider()
            ->get(route('rider.notifications.index'))
            ->assertOk()
            ->assertSee('My pickup assignment')
            ->assertSee('Mark all as read')
            ->assertDontSee('Another rider pickup');
    }

    public function test_notifications_page_renders_the_empty_state(): void
    {
        $this->actingAsRider()
            ->get(route('rider.notifications.index'))
            ->assertOk()
            ->assertSee('No notifications yet');
    }

    public function test_suspended_rider_cannot_reach_the_notification_centre_or_open_a_link(): void
    {
        $notification = $this->fakeNotification($this->rider, $this->payload('Old task'));

        $this->rider->forceFill(['status' => Rider::STATUS_SUSPENDED])->save();

        $this->actingAsRider()
            ->get(route('rider.notifications.index'))
            ->assertRedirect(route('home', ['login' => 1]));

        $this->actingAsRider()
            ->post(route('rider.notifications.open', $notification->id))
            ->assertRedirect(route('home', ['login' => 1]));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function secondStation(): LogisticsHub
    {
        $first = $this->stationForProvider($this->provider);

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $first->parent_hub_id,
            'name' => 'Second Station Hub '.$this->provider->id,
            'hub_type' => LogisticsHub::STATION,
            'province_name' => 'Pampanga',
            'municipality' => 'Angeles',
            'city_municipality_code' => '030540200',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $title): array
    {
        return [
            'type' => 'rider_test',
            'title' => $title,
            'message' => $title.' message',
            'url' => route('rider.pickups.index'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function fakeNotification(Rider $rider, array $data, mixed $readAt = null): DatabaseNotification
    {
        return $rider->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $data['type'] ?? 'rider_test',
            'data' => $data,
            'read_at' => $readAt,
        ]);
    }
}
