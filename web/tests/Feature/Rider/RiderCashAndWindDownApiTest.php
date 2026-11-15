<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Http\Middleware\Rider\EnsureRiderActive;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LogisticsRemittanceService;

/**
 * GET /api/rider/cod (read-only COD cash from RiderCashService) and the
 * wind-down contract the app relies on (winding_down + the web's message,
 * and the routes a wind-down closes).
 */
class RiderCashAndWindDownApiTest extends RiderTestCase
{
    private function api(?Rider $rider = null): static
    {
        $this->app['auth']->forgetGuards();
        $token = ($rider ?? $this->rider)->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function ledger(Courier $courier, int $collected, ?int $received = null, ?string $tracking = null): CodRemittance
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['tracking_number' => $tracking]);
        $payment = Payment::factory()->paid()->create(['seller_order_id' => $parcel->id, 'amount_minor' => $collected]);
        $payment->forceFill(['collected_by_type' => 'rider', 'collected_by_id' => $courier->id, 'collected_amount_minor' => $collected, 'collected_at' => now()])->save();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'payment_id' => $payment->id, 'seller_order_id' => $parcel->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'courier_id' => $courier->id,
            'amount_minor' => $collected,
            'status' => $received === null ? CodRemittance::PENDING_REMITTANCE : CodRemittance::REMITTED_TO_LOGISTICS,
            'received_amount_minor' => $received,
            'confirmed_at' => $received === null ? null : now(),
        ])->save();

        return $remittance;
    }

    private function windDown(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.logistics.suspend', $this->provider), ['reason' => 'Unremitted COD under review.'])
            ->assertSessionHas('success');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.logistics.rider-wind-down', $this->provider), [
                'hours' => 24,
                'reason' => 'Let riders finish parcels already with buyers.',
            ])
            ->assertSessionHas('success');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_cod_shows_only_my_server_records_held_and_remitted(): void
    {
        $held = $this->ledger($this->courier, 150000, tracking: 'TRK-HELD');
        $old = $this->ledger($this->courier, 20000, tracking: 'TRK-OLD');
        $old->forceFill(['created_at' => now()->subDays(LogisticsRemittanceService::OVERDUE_AFTER_DAYS + 1)])->save();
        $this->ledger($this->courier, 50000, 49000, 'TRK-DONE'); // short handover confirmed by Logistics
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->ledger($other, 999900);

        $response = $this->api()->getJson('/api/rider/cod')->assertOk()
            ->assertJsonPath('data.summary.holding_minor', 170000)
            ->assertJsonPath('data.summary.holding_count', 2)
            ->assertJsonPath('data.summary.overdue_count', 1)
            ->assertJsonPath('data.summary.remitted_minor', 49000)
            ->assertJsonPath('data.summary.collected_minor', 220000)
            ->assertJsonCount(2, 'data.holding')
            ->assertJsonCount(1, 'data.remitted')
            ->assertJsonPath('data.remitted.0.tracking_number', 'TRK-DONE')
            ->assertJsonPath('data.remitted.0.status', CodRemittance::REMITTED_TO_LOGISTICS)
            ->assertJsonPath('data.remitted.0.received_amount_minor', 49000);

        $holding = collect($response->json('data.holding'))->keyBy('id');
        $this->assertTrue($holding[$old->id]['overdue']);
        $this->assertFalse($holding[$held->id]['overdue']);
        $this->assertSame(CodRemittance::PENDING_REMITTANCE, $holding[$held->id]['status']);
        $this->assertStringNotContainsString('999900', $response->getContent());
    }

    public function test_cod_is_read_only_and_needs_a_rider_token(): void
    {
        $this->ledger($this->courier, 10000);

        $this->getJson('/api/rider/cod')->assertUnauthorized();
        $buyerToken = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->getJson('/api/rider/cod')->assertForbidden();

        // There is no rider endpoint that creates or confirms a remittance.
        $this->api()->postJson('/api/rider/cod', ['amount' => 100])->assertStatus(405);
        $this->assertSame(1, CodRemittance::query()->where('status', CodRemittance::PENDING_REMITTANCE)->count());
    }

    public function test_a_wind_down_rider_keeps_the_cod_page_and_gets_the_web_notice(): void
    {
        $this->ledger($this->courier, 10000);
        $this->windDown();

        $this->api()->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonPath('data.winding_down', true)
            ->assertJsonPath('data.winding_down_message', EnsureRiderActive::WIND_DOWN_MESSAGE)
            ->assertJsonCount(0, 'data.pickups');
        $this->api()->getJson('/api/rider/cod')->assertOk()->assertJsonPath('data.summary.holding_minor', 10000);

        // Closed during a wind-down, as on the web.
        $this->api()->getJson('/api/rider/return-pickups')->assertForbidden();
        $this->api()->postJson('/api/rider/availability', ['online' => true])->assertForbidden();
    }

    public function test_an_operational_rider_gets_no_wind_down_notice(): void
    {
        $this->api()->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonPath('data.winding_down', false)
            ->assertJsonPath('data.winding_down_message', null);
    }
}
