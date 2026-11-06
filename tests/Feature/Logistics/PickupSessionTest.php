<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\PickupSession;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\PickupSessionService;
use App\Services\Logistics\PickupWindowService;
use App\Services\Rider\RiderEarningService;
use App\Services\Seller\SellerOrderStatusService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class PickupSessionTest extends TestCase
{
    use CreatesAssignableFleet;
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $first;

    private Courier $second;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 9 AM Manila on a Wednesday: inside the morning window.
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00:00', 'Asia/Manila'));
        $this->provider = LogisticsProvider::factory()->create();
        $this->first = $this->assignableCourier($this->provider, ['max_weight_grams' => 20000], 'San Fernando', '030540100');
        $this->second = $this->assignableCourier($this->provider, ['max_weight_grams' => 20000], 'San Fernando', '030540100');
        $this->seller = Seller::factory()->create(['barangay_code' => '0305401001']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parcel(?Seller $seller = null, OrderStatus $status = OrderStatus::Preparing): SellerOrder
    {
        $seller ??= $this->seller;

        return SellerOrder::factory()->create([
            'seller_id' => $seller->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001', 'municipality' => 'San Fernando']])->id,
            'status' => $status->value,
            'carrier_provider_id' => $this->provider->id,
            'origin_station_hub_id' => $this->first->rider->station_hub_id,
            'tracking_number' => null,
        ]);
    }

    private function ready(SellerOrder $parcel): SellerOrder
    {
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller, actorId: $parcel->seller_id);

        return $parcel->fresh();
    }

    private function collect(SellerOrder $parcel): SellerOrder
    {
        $parcel = $parcel->fresh();
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::PickedUp,
            actor: OrderActor::Rider, actorId: (int) $parcel->courier_id);

        return $parcel->fresh();
    }

    private function earning(SellerOrder $parcel): ?RiderEarning
    {
        return RiderEarning::query()->where('seller_order_id', $parcel->id)->where('job', RiderEarning::PICKUP)->first();
    }

    private function setSetting(string $key, string $value): void
    {
        PlatformSetting::query()->where('key', $key)->update(['value' => $value]);
    }

    // ── Windows ──────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function boundaries(): array
    {
        return [
            '8:00 AM opens the morning' => ['2026-11-04 08:00', 'morning', '2026-11-04'],
            'before opening waits for the morning' => ['2026-11-04 05:00', 'morning', '2026-11-04'],
            '11:30 AM still has the minimum lead' => ['2026-11-04 11:30', 'morning', '2026-11-04'],
            '11:31 AM is too late for the morning' => ['2026-11-04 11:31', 'afternoon', '2026-11-04'],
            '12:00 PM is between windows' => ['2026-11-04 12:00', 'afternoon', '2026-11-04'],
            '1:00 PM opens the afternoon' => ['2026-11-04 13:00', 'afternoon', '2026-11-04'],
            '4:30 PM still has the minimum lead' => ['2026-11-04 16:30', 'afternoon', '2026-11-04'],
            '4:31 PM rolls to tomorrow' => ['2026-11-04 16:31', 'morning', '2026-11-05'],
            '5:00 PM rolls to tomorrow' => ['2026-11-04 17:00', 'morning', '2026-11-05'],
            'after hours rolls to tomorrow' => ['2026-11-04 22:15', 'morning', '2026-11-05'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_a_ready_parcel_is_booked_into_the_window_open_now_or_the_next_one(string $manila, string $key, string $day): void
    {
        $window = app(PickupWindowService::class)->windowFor(Carbon::parse($manila, 'Asia/Manila'));

        $this->assertSame($key, $window['key']);
        $this->assertSame($day, $window['starts_at']->copy()->setTimezone('Asia/Manila')->toDateString());
    }

    public function test_marking_ready_books_the_window_and_shows_it_to_the_seller_without_waiting_for_it_to_close(): void
    {
        $parcel = $this->ready($this->parcel());

        $this->assertSame('morning', $parcel->pickup_window_key);
        $this->assertSame('08:00', $parcel->pickup_window_starts_at->copy()->setTimezone('Asia/Manila')->format('H:i'));
        $this->assertSame('12:00', $parcel->pickup_window_ends_at->copy()->setTimezone('Asia/Manila')->format('H:i'));
        // A rider is reserved straight away, not when the window closes.
        $this->assertNotNull($parcel->courier_id);

        $this->actingAs($this->seller, 'seller')->get(route('seller.orders.show', $parcel))
            ->assertOk()->assertSee('Pickup scheduled:')->assertSee('Today, 8:00 AM – 12:00 PM');
        $this->get(route('seller.orders.index'))->assertOk()->assertSee('Pickup scheduled:');
    }

    public function test_admin_edits_windows_with_validation_and_a_recorded_history(): void
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $update = fn (string $key, string $value) => $this->actingAs($admin, 'admin')->patch(
            route('admin.settings.update', $key),
            ['value' => $value, 'expected_value' => PlatformSetting::query()->where('key', $key)->value('value')],
        );

        $update('pickup_window_morning', '08:00-13:30')->assertSessionHasErrors('value');
        $update('pickup_window_morning', '12:00-08:00')->assertSessionHasErrors('value');
        $update('pickup_window_morning', 'morning')->assertSessionHasErrors('value');
        $this->assertSame('08:00-12:00', PlatformSetting::query()->where('key', 'pickup_window_morning')->value('value'));

        $update('pickup_window_afternoon', '14:00-18:00')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_setting_histories', ['setting_key' => 'pickup_window_afternoon', 'previous_value' => '13:00-17:00', 'new_value' => '14:00-18:00', 'admin_id' => $admin->id]);

        $window = app(PickupWindowService::class)->windowFor(Carbon::parse('2026-11-04 12:00', 'Asia/Manila'));
        $this->assertSame('afternoon', $window['key']);
        $this->assertSame('14:00', $window['starts_at']->copy()->setTimezone('Asia/Manila')->format('H:i'));
    }

    public function test_rider_rates_are_validated_and_only_admins_can_change_them(): void
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $patch = fn (string $key, string $value, Admin $as) => $this->actingAs($as, 'admin')->patch(
            route('admin.settings.update', $key),
            ['value' => $value, 'expected_value' => PlatformSetting::query()->where('key', $key)->value('value')],
        );

        $patch('rider_pickup_additional_rate', '35.00', $admin)->assertSessionHasErrors('value');
        $patch('rider_pickup_first_rate', '3', $admin)->assertSessionHasErrors('value');
        $patch('rider_pickup_first_rate', '-5', $admin)->assertSessionHasErrors('value');
        $patch('rider_delivery_rate', '0', $admin)->assertSessionHasErrors('value');
        $patch('rider_pickup_first_rate', '40.50', $admin)->assertSessionHasNoErrors();
        $this->assertSame('40.50', PlatformSetting::query()->where('key', 'rider_pickup_first_rate')->value('value'));

        $patch('rider_pickup_first_rate', '45.00', Admin::factory()->create(['role' => 'moderator']))->assertForbidden();
        $this->assertSame('40.50', PlatformSetting::query()->where('key', 'rider_pickup_first_rate')->value('value'));
        $this->actingAs($admin, 'admin')->get(route('admin.settings.index'))->assertSee('Morning pickup window')
            ->assertSee('Rider pay: first parcel of a seller pickup');
    }

    // ── Sessions ─────────────────────────────────────────────────────────────

    public function test_parcels_from_one_seller_in_one_window_share_one_rider_and_one_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());

        $this->assertNotNull($a->pickup_session_id);
        $this->assertSame($a->pickup_session_id, $b->pickup_session_id);
        $this->assertSame($a->courier_id, $b->courier_id);
        $this->assertSame(1, PickupSession::query()->count());
        $this->assertSame(PickupSession::OPEN, PickupSession::query()->sole()->status);
        $this->assertSame(2, PickupSession::query()->sole()->parcels()->count());
    }

    public function test_different_sellers_always_get_separate_sessions_and_each_pays_a_first_parcel(): void
    {
        $other = Seller::factory()->create(['barangay_code' => '0305401001']);
        $service = app(CourierAssignmentService::class);
        $a = $this->parcel();
        $b = $this->parcel($other);
        $this->setReady($a);
        $this->setReady($b);
        // The same rider visits both sellers on one trip.
        $service->reservePickup($a->fresh(), $this->provider, $this->first->id);
        $service->reservePickup($b->fresh(), $this->provider, $this->first->id);

        $this->assertNotSame($a->fresh()->pickup_session_id, $b->fresh()->pickup_session_id);
        $this->assertSame(2, PickupSession::query()->count());

        $this->collect($a);
        $this->collect($b);

        $this->assertSame(3000, $this->earning($a)->amount_minor);
        $this->assertSame(3000, $this->earning($b)->amount_minor);
        $this->assertSame(2, RiderEarning::query()->where('session_first', 1)->count());
    }

    public function test_a_different_pickup_address_is_a_separate_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->parcel();
        $b->forceFill(['pickup_address_snapshot' => ['full' => '99 Another Street, Angeles', 'barangay_code' => '0305401001']])->save();
        $b = $this->ready($b);

        $this->assertNotSame($a->pickup_session_id, $b->pickup_session_id);
    }

    public function test_first_parcel_pays_30_each_additional_5_and_the_session_closes_when_all_are_collected(): void
    {
        $parcels = [$this->ready($this->parcel()), $this->ready($this->parcel()), $this->ready($this->parcel())];
        $sessionId = $parcels[0]->pickup_session_id;

        $this->collect($parcels[0]);
        $this->assertSame(PickupSession::IN_PROGRESS, PickupSession::query()->find($sessionId)->status);
        $this->collect($parcels[1]);
        $this->collect($parcels[2]);

        $this->assertSame([3000, 500, 500], collect($parcels)->map(fn ($p) => $this->earning($p)->amount_minor)->all());
        $this->assertSame(1, RiderEarning::query()->where('pickup_session_id', $sessionId)->where('session_first', 1)->count());
        $session = PickupSession::query()->find($sessionId);
        $this->assertSame(PickupSession::COMPLETED, $session->status);
        $this->assertSame('all_collected', $session->close_reason);
        $this->assertNotNull($session->closed_at);
    }

    public function test_repeating_a_collection_never_pays_twice(): void
    {
        $a = $this->ready($this->parcel());
        $this->collect($a);
        // A repeated confirmation is a no-op in the status service, and a repeated credit finds the row.
        $this->collect($a);
        app(RiderEarningService::class)->creditFor($a->fresh(), OrderStatus::PickedUp);

        $this->assertSame(1, RiderEarning::query()->where('seller_order_id', $a->id)->count());
        $this->assertSame(3000, $this->earning($a)->amount_minor);
    }

    public function test_the_database_refuses_a_second_first_parcel_payment_in_a_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        $this->collect($a);
        $earning = $this->earning($a);

        $this->expectException(QueryException::class);
        (new RiderEarning)->forceFill([
            'seller_order_id' => $b->id, 'job' => RiderEarning::PICKUP, 'courier_id' => $earning->courier_id,
            'logistics_provider_id' => $earning->logistics_provider_id, 'amount_minor' => 3000, 'earned_at' => now(),
            'pickup_session_id' => $earning->pickup_session_id, 'session_first' => 1,
        ])->save();
    }

    public function test_membership_freezes_when_the_visit_starts_and_late_parcels_use_a_new_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        $this->collect($a);

        $late = $this->ready($this->parcel());

        $this->assertNotSame($a->fresh()->pickup_session_id, $late->pickup_session_id);
        $this->assertSame($b->fresh()->pickup_session_id, $a->fresh()->pickup_session_id);
        $this->assertSame(PickupSession::OPEN, PickupSession::query()->find($late->pickup_session_id)->status);
    }

    public function test_a_quick_second_pass_continues_the_visit_and_a_real_second_visit_pays_a_new_first_parcel(): void
    {
        $a = $this->readyWith($this->first);
        $this->collect($a);
        $this->assertSame(PickupSession::COMPLETED, PickupSession::query()->find($a->fresh()->pickup_session_id)->status);

        // Marked ready minutes later: same rider, same seller, no real second trip.
        Carbon::setTestNow(now()->addMinutes(5));
        $quick = $this->collect($this->readyWith($this->first));
        $this->assertSame(500, $this->earning($quick)->amount_minor);
        $this->assertNull($this->earning($quick)->session_first);
        $this->assertFalse(PickupSession::query()->find($quick->pickup_session_id)->first_payable);

        // Much later in the same window: a verified second visit.
        Carbon::setTestNow(now()->addMinutes(45));
        $again = $this->collect($this->readyWith($this->first));
        $this->assertSame(3000, $this->earning($again)->amount_minor);
        $this->assertSame(1, $this->earning($again)->session_first);
        $this->assertNotSame($a->fresh()->pickup_session_id, $again->pickup_session_id);
    }

    public function test_the_other_rider_collecting_from_the_same_seller_is_a_separate_visit(): void
    {
        $a = $this->readyWith($this->first);
        $this->collect($a);

        $other = $this->collect($this->readyWith($this->second));

        $this->assertSame(3000, $this->earning($other)->amount_minor);
        $this->assertNotSame($a->fresh()->pickup_session_id, $other->pickup_session_id);
    }

    public function test_a_cancelled_parcel_earns_nothing_and_leaves_the_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        app(SellerOrderStatusService::class)->transition($a, OrderStatus::Cancelled, actor: OrderActor::Seller, actorId: $this->seller->id);

        $this->assertNull($this->earning($a));
        $this->assertNull($a->fresh()->pickup_session_id);
        $this->assertSame(1, PickupSession::query()->sole()->parcels()->count());

        // The last parcel leaving an unstarted session cancels it.
        app(SellerOrderStatusService::class)->transition($b, OrderStatus::Cancelled, actor: OrderActor::Seller, actorId: $this->seller->id);
        $this->assertSame(PickupSession::CANCELLED, PickupSession::query()->sole()->status);
        $this->assertSame(0, RiderEarning::query()->count());
    }

    public function test_reassigning_a_parcel_moves_it_to_the_new_riders_session(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        $oldSession = $a->pickup_session_id;
        $courierId = (int) $a->courier_id;
        $replacement = $courierId === $this->first->id ? $this->second : $this->first;

        $moved = app(CourierAssignmentService::class)->reassign($a, $this->provider, 'pickup', $replacement->id, 'Rider is unwell today', $courierId);

        $this->assertSame($replacement->id, $moved->id);
        $this->assertNotSame($oldSession, $a->fresh()->pickup_session_id);
        $this->assertSame($replacement->id, (int) PickupSession::query()->find($a->fresh()->pickup_session_id)->courier_id);
        $this->assertSame($oldSession, $b->fresh()->pickup_session_id);
        $this->assertSame(1, PickupSession::query()->find($oldSession)->parcels()->count());
    }

    public function test_finishing_a_stop_keeps_uncollected_parcels_assigned_for_the_next_visit(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        $this->collect($a);
        $rider = Courier::query()->find($a->fresh()->courier_id)->rider;

        $this->actingAs($rider, 'rider')->post(route('rider.pickups.stops.finish', $a->fresh()->pickup_session_id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(PickupSession::COMPLETED, PickupSession::query()->find($a->fresh()->pickup_session_id)->status);
        $left = $b->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $left->status);
        $this->assertSame($a->fresh()->courier_id, $left->courier_id);
        $this->assertNotSame($a->fresh()->pickup_session_id, $left->pickup_session_id);

        // Another rider cannot finish someone else's stop.
        $other = $rider->courier->id === $this->first->id ? $this->second : $this->first;
        $this->actingAs($other->rider, 'rider')->post(route('rider.pickups.stops.finish', $left->pickup_session_id))->assertForbidden();
    }

    // ── Missed windows ───────────────────────────────────────────────────────

    public function test_a_missed_window_reschedules_without_counting_as_a_failed_pickup(): void
    {
        $a = $this->ready($this->parcel());
        $courierId = $a->courier_id;
        $oldSession = $a->pickup_session_id;

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:05:00', 'Asia/Manila'));
        $this->assertSame(1, app(PickupSessionService::class)->rollMissedWindows());

        $a = $a->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $a->status);
        $this->assertSame('afternoon', $a->pickup_window_key);
        $this->assertSame(1, $a->pickup_missed_windows);
        $this->assertSame($courierId, $a->courier_id);
        $this->assertSame(0, $a->pickupAttempts()->count());
        $this->assertNotSame($oldSession, $a->pickup_session_id);
        $this->assertSame(PickupSession::MISSED, PickupSession::query()->find($oldSession)->status);
        $this->assertSame(0, RiderEarning::query()->count());

        // Rolled once; a second run in the same window changes nothing.
        $this->assertSame(0, app(PickupSessionService::class)->rollMissedWindows());

        Carbon::setTestNow(Carbon::parse('2026-11-04 17:10:00', 'Asia/Manila'));
        $this->artisan('pickups:roll-missed-windows')->assertSuccessful();
        $a = $a->fresh();
        $this->assertSame('morning', $a->pickup_window_key);
        $this->assertSame('2026-11-05', $a->pickup_window_starts_at->copy()->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame(2, $a->pickup_missed_windows);
        $this->assertSame(0, $a->pickupAttempts()->count());
    }

    public function test_an_unavailable_rider_leaves_the_rolled_parcel_unsessioned_until_it_is_reassigned(): void
    {
        $a = $this->readyWith($this->first);
        $this->first->rider->forceFill(['status' => 'suspended'])->save();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:30:00', 'Asia/Manila'));
        app(PickupSessionService::class)->rollMissedWindows();

        $a = $a->fresh();
        $this->assertNull($a->pickup_session_id);
        $this->assertSame(OrderStatus::ReadyForPickup, $a->status);
        $this->assertSame(0, $a->pickupAttempts()->count());

        $moved = app(CourierAssignmentService::class)->reassign($a, $this->provider, 'pickup', $this->second->id, 'Original rider suspended', $this->first->id);
        $this->assertSame($this->second->id, $moved->id);
        $this->assertSame($this->second->id, (int) PickupSession::query()->find($a->fresh()->pickup_session_id)->courier_id);
    }

    public function test_a_parcel_collected_after_its_window_still_pays_normally(): void
    {
        $a = $this->ready($this->parcel());
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:20:00', 'Asia/Manila'));

        $this->collect($a);

        $this->assertSame(3000, $this->earning($a)->amount_minor);
    }

    // ── Rates ────────────────────────────────────────────────────────────────

    public function test_rate_changes_apply_to_new_sessions_only_and_never_touch_recorded_earnings(): void
    {
        $a = $this->ready($this->parcel());
        $b = $this->ready($this->parcel());
        $this->collect($a);
        $recorded = $this->earning($a);

        $this->setSetting('rider_pickup_first_rate', '40.00');
        $this->setSetting('rider_pickup_additional_rate', '8.00');
        // The open session keeps the rates it was created with.
        $this->collect($b);
        $this->assertSame(500, $this->earning($b)->amount_minor);
        $this->assertSame(3000, $recorded->fresh()->amount_minor);

        // A session opened afterwards uses the new rates.
        Carbon::setTestNow(now()->addMinutes(50));
        $c = $this->ready($this->parcel());
        $d = $this->ready($this->parcel());
        $this->collect($c);
        $this->collect($d);
        $this->assertSame(4000, $this->earning($c)->amount_minor);
        $this->assertSame(800, $this->earning($d)->amount_minor);
        $this->assertSame(3000, $recorded->fresh()->amount_minor);
    }

    public function test_delivery_pay_follows_the_configured_rate_and_defaults_to_50(): void
    {
        $parcel = $this->parcel(status: OrderStatus::OutForDelivery);
        $parcel->forceFill(['delivery_courier_id' => $this->second->id])->save();

        app(RiderEarningService::class)->creditFor($parcel->fresh(), OrderStatus::Delivered);
        $this->assertSame(5000, RiderEarning::query()->where('seller_order_id', $parcel->id)->where('job', RiderEarning::DELIVERY)->value('amount_minor'));

        $this->setSetting('rider_delivery_rate', '60.00');
        $other = $this->parcel(status: OrderStatus::OutForDelivery);
        $other->forceFill(['delivery_courier_id' => $this->second->id])->save();
        app(RiderEarningService::class)->creditFor($other->fresh(), OrderStatus::Delivered);
        $this->assertSame(6000, RiderEarning::query()->where('seller_order_id', $other->id)->where('job', RiderEarning::DELIVERY)->value('amount_minor'));
        $this->assertSame(5000, RiderEarning::query()->where('seller_order_id', $parcel->id)->where('job', RiderEarning::DELIVERY)->value('amount_minor'));
    }

    public function test_a_parcel_with_no_session_still_earns_the_first_parcel_rate(): void
    {
        // Booked before sessions existed: a rider already holds it.
        $legacy = $this->parcel(status: OrderStatus::ReadyForPickup);
        $legacy->forceFill(['courier_id' => $this->first->id])->save();

        $this->collect($legacy);

        $this->assertSame(3000, $this->earning($legacy)->amount_minor);
        $this->assertNotNull($legacy->fresh()->pickup_session_id);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function test_the_rider_sees_one_stop_card_with_progress_window_and_earnings(): void
    {
        $a = $this->ready($this->parcel());
        $this->ready($this->parcel());
        $this->collect($a);
        $rider = Courier::query()->find($a->fresh()->courier_id)->rider;

        $this->actingAs($rider, 'rider')->get(route('rider.pickups.index'))->assertOk()
            ->assertSee('data-pickup-stop', false)
            ->assertSeeInOrder(['Pickup window', 'Today, 8:00 AM – 12:00 PM', '1 of 2 collected', 'This stop pays', '+₱30.00', 'up to +₱35.00'])
            ->assertSee('Finish this stop');
        $this->assertSame(1, substr_count($this->get(route('rider.pickups.index'))->getContent(), 'data-pickup-stop'));
    }

    public function test_logistics_sees_the_window_session_and_missed_marker_in_the_pickup_queue(): void
    {
        $a = $this->ready($this->parcel());
        $this->ready($this->parcel());
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.incoming-parcels.index'))->assertOk()
            ->assertSee('data-pickup-schedule', false)->assertSee('Today, 8:00 AM – 12:00 PM')
            ->assertSee('Session #'.$a->pickup_session_id)->assertSee('2 parcels');

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:30:00', 'Asia/Manila'));
        app(PickupSessionService::class)->rollMissedWindows();
        $this->get(route('logistics.incoming-parcels.index'))->assertOk()->assertSee('Rescheduled ×1');
    }

    public function test_a_missed_window_is_shown_to_the_seller(): void
    {
        $a = $this->ready($this->parcel());
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:30:00', 'Asia/Manila'));
        app(PickupSessionService::class)->rollMissedWindows();

        $this->actingAs($this->seller, 'seller')->get(route('seller.orders.show', $a))->assertOk()
            ->assertSee('Today, 1:00 PM – 5:00 PM')->assertSee('moved to the next window');
    }

    /** Ready, with the named rider chosen by Logistics instead of the automatic match. */
    private function readyWith(Courier $courier): SellerOrder
    {
        $parcel = $this->parcel();
        $this->setReady($parcel);
        app(CourierAssignmentService::class)->reservePickup($parcel->fresh(), $this->provider, $courier->id);

        return $parcel->fresh();
    }

    private function setReady(SellerOrder $parcel): void
    {
        // Ready without auto-dispatch, so a test can choose the rider itself.
        $parcel->forceFill(['status' => OrderStatus::ReadyForPickup->value])->save();
        app(PickupWindowService::class)->schedule($parcel);
    }
}
