<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\PickupSession;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * Pickup stops in the rider API: GET /api/rider/jobs groups pickups by
 * PickupSession with the server's collected/remaining counts, and
 * POST /api/rider/pickups/stops/{session}/finish delegates to
 * PickupSessionService::finish (locks, ownership, idempotency, earnings).
 */
class RiderPickupStopApiTest extends TestCase
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

    private function readyParcel(): SellerOrder
    {
        $parcel = SellerOrder::factory()->create([
            'seller_id' => $this->seller->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001', 'municipality' => 'San Fernando']])->id,
            'status' => OrderStatus::Preparing->value,
            'carrier_provider_id' => $this->provider->id,
            'origin_station_hub_id' => $this->first->rider->station_hub_id,
            'tracking_number' => null,
        ]);
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller, actorId: $parcel->seller_id);

        return $parcel->fresh();
    }

    private function collect(SellerOrder $parcel): void
    {
        $parcel = $parcel->fresh();
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::PickedUp,
            actor: OrderActor::Rider, actorId: (int) $parcel->courier_id);
    }

    private function api(Courier $courier): static
    {
        $this->app['auth']->forgetGuards();
        $token = $courier->rider->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function finish(Courier $courier, int $sessionId): TestResponse
    {
        return $this->api($courier)->postJson("/api/rider/pickups/stops/{$sessionId}/finish");
    }

    /** @return array{0: SellerOrder, 1: SellerOrder, 2: Courier} two parcels in one stop, one collected */
    private function halfCollectedStop(): array
    {
        $a = $this->readyParcel();
        $b = $this->readyParcel();
        $this->collect($a);
        $courier = Courier::query()->findOrFail($a->fresh()->courier_id);

        return [$a->fresh(), $b->fresh(), $courier];
    }

    public function test_jobs_group_pickups_by_stop_with_server_counts(): void
    {
        [$a, $b, $courier] = $this->halfCollectedStop();

        $response = $this->api($courier)->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonCount(1, 'data.stops')
            ->assertJsonPath('data.stops.0.id', $a->pickup_session_id)
            ->assertJsonPath('data.stops.0.status', PickupSession::IN_PROGRESS)
            ->assertJsonPath('data.stops.0.collected', 1)
            ->assertJsonPath('data.stops.0.total', 2)
            ->assertJsonPath('data.stops.0.remaining', 1)
            ->assertJsonPath('data.stops.0.can_finish', true);

        // The collected parcel stays listed (PICKED_UP) beside the one still waiting,
        // both tagged with the stop they belong to.
        $pickups = collect($response->json('data.pickups'))->keyBy('id');
        $this->assertSame(OrderStatus::PickedUp->value, $pickups[$a->id]['status']);
        $this->assertSame(OrderStatus::ReadyForPickup->value, $pickups[$b->id]['status']);
        $this->assertSame((int) $a->pickup_session_id, (int) $pickups[$b->id]['pickup_session_id']);
    }

    public function test_finishing_a_stop_keeps_uncollected_parcels_with_the_rider_and_credits_nothing_new(): void
    {
        [$a, $b, $courier] = $this->halfCollectedStop();
        $sessionId = (int) $a->pickup_session_id;
        $earningsBefore = RiderEarning::query()->count();

        $this->finish($courier, $sessionId)->assertOk()
            ->assertJsonPath('data.stop.id', $sessionId)
            ->assertJsonPath('data.stop.status', PickupSession::COMPLETED)
            ->assertJsonPath('data.stop.can_finish', false);

        $this->assertSame(PickupSession::COMPLETED, PickupSession::query()->find($sessionId)->status);
        $left = $b->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $left->status);
        $this->assertSame((int) $courier->id, (int) $left->courier_id);
        $this->assertNotSame($sessionId, (int) $left->pickup_session_id);
        $this->assertSame($earningsBefore, RiderEarning::query()->count());

        // A repeat (lost response) is a no-op: no new session, no new earning.
        $nextSession = (int) $left->pickup_session_id;
        $this->finish($courier, $sessionId)->assertOk()->assertJsonPath('data.stop.status', PickupSession::COMPLETED);
        $this->assertSame($nextSession, (int) $b->fresh()->pickup_session_id);
        $this->assertSame($earningsBefore, RiderEarning::query()->count());
    }

    public function test_another_riders_stop_is_forbidden_and_unchanged(): void
    {
        [$a, , $courier] = $this->halfCollectedStop();
        $other = (int) $courier->id === (int) $this->first->id ? $this->second : $this->first;

        $this->finish($other, (int) $a->pickup_session_id)->assertForbidden();

        $this->assertSame(PickupSession::IN_PROGRESS, PickupSession::query()->find($a->pickup_session_id)->status);
    }

    public function test_a_stop_with_nothing_collected_cannot_be_finished(): void
    {
        $a = $this->readyParcel();
        $courier = Courier::query()->findOrFail($a->courier_id);

        $this->finish($courier, (int) $a->pickup_session_id)->assertStatus(422)->assertJsonValidationErrors('session');

        $this->assertSame(PickupSession::OPEN, PickupSession::query()->find($a->pickup_session_id)->status);
        $this->assertSame((int) $a->pickup_session_id, (int) $a->fresh()->pickup_session_id);
    }

    public function test_requires_a_rider_bearer_token(): void
    {
        [$a] = $this->halfCollectedStop();

        $this->postJson("/api/rider/pickups/stops/{$a->pickup_session_id}/finish")->assertUnauthorized();
        $this->assertSame(PickupSession::IN_PROGRESS, PickupSession::query()->find($a->pickup_session_id)->status);
    }
}
