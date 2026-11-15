<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Rider\RiderEarning;
use App\Models\Shared\Incidents;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Rider mobile API, second pass: wind-down parity with the web panel, token
 * renewal, incidents, notifications, delivery history, the registration
 * provider list and server earnings totals. Every endpoint is a bearer
 * adapter over an existing service; these tests pin the contract and the
 * gates, not the services' own rules (covered by their web tests).
 */
class RiderMobileOpsApiTest extends RiderTestCase
{
    private function token(?Rider $rider = null): string
    {
        $this->app['auth']->forgetGuards();

        return ($rider ?? $this->rider)->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;
    }

    private function api(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token())->withHeader('Accept', 'application/json');
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

    private function outForDelivery(): SellerOrder
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 150000]);

        return $parcel->fresh();
    }

    public function test_a_wind_down_rider_can_sign_in_and_only_finish_parcels_already_out(): void
    {
        $out = $this->outForDelivery();
        $waiting = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $this->windDown();

        $login = $this->postJson('/api/rider/login', ['email' => $this->rider->email, 'password' => 'Rider@2026'])
            ->assertOk()->json('data.token');

        $this->api($login)->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonPath('data.winding_down', true)
            ->assertJsonCount(0, 'data.pickups')
            ->assertJsonCount(1, 'data.deliveries')
            ->assertJsonPath('data.deliveries.0.id', $out->id);

        // The routes the web closes during a wind-down stay closed here.
        $this->api($login)->postJson('/api/rider/availability', ['online' => true])->assertForbidden()
            ->assertJsonPath('success', false);
        $this->api($login)->postJson("/api/rider/pickups/{$waiting->id}/confirm", [
            'verification_token' => 'irrelevant',
        ])->assertForbidden();
        $this->api($login)->getJson('/api/rider/history')->assertForbidden();

        // Finishing the parcel already out for delivery works.
        $payload = $this->withHandoff($out, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'cod_amount' => '1500.00',
        ]);
        $this->api($login)->post("/api/rider/deliveries/{$out->id}/deliver", $payload)
            ->assertOk()->assertJsonPath('data.status', OrderStatus::Delivered->value);
        $this->assertSame(OrderStatus::ReadyForPickup, $waiting->fresh()->status);
    }

    public function test_a_suspended_rider_without_a_wind_down_is_still_refused(): void
    {
        $token = $this->token();
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.users.logistics.suspend', $this->provider), ['reason' => 'Unremitted COD under review.']);

        $this->api($token)->getJson('/api/rider/jobs')->assertForbidden();
        $this->api($token)->postJson('/api/rider/token/refresh')->assertForbidden();
        $this->postJson('/api/rider/login', ['email' => $this->rider->email, 'password' => 'Rider@2026'])->assertForbidden();
    }

    public function test_token_refresh_rotates_the_token_with_the_same_ability_and_lifetime(): void
    {
        $old = $this->token();

        $response = $this->api($old)->postJson('/api/rider/token/refresh')->assertOk()
            ->assertJsonPath('data.role', 'rider')
            ->assertJsonPath('data.token_type', 'Bearer');

        $new = $response->json('data.token');
        $this->assertNotSame($old, $new);
        $this->assertNull(PersonalAccessToken::findToken($old));
        $record = PersonalAccessToken::findToken($new);
        $this->assertSame(['rider'], $record->abilities);
        $this->assertEqualsWithDelta(now()->addMinutes(config('sanctum.expiration', 60))->getTimestamp(), $record->expires_at->getTimestamp(), 5);

        $this->api($old)->getJson('/api/rider/profile')->assertUnauthorized();
        $this->api($new)->getJson('/api/rider/profile')->assertOk();
    }

    public function test_an_expired_or_buyer_token_cannot_be_refreshed(): void
    {
        $expired = $this->rider->createToken('api-token', ['rider'], now()->subMinute())->plainTextToken;
        $this->api($expired)->postJson('/api/rider/token/refresh')->assertUnauthorized();

        $buyer = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->api($buyer)->postJson('/api/rider/token/refresh')->assertForbidden();
    }

    public function test_incident_report_reuses_the_incident_service_and_its_guards(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, ['courier_id' => $this->courier->id]);
        $parcel->forceFill(['handover_at' => now()])->save();
        StatusHistory::record($parcel, OrderStatus::ReadyForPickup, OrderStatus::PickedUp,
            actor: OrderActor::Rider, actorId: $this->courier->id, source: 'web');
        $payload = ['type' => 'lost', 'reason' => 'Parcel could not be accounted for.', 'description' => 'Checked the vehicle and the hub register.'];

        // Another rider cannot report on my parcel.
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $this->api($this->token($other))->postJson("/api/rider/jobs/{$parcel->id}/incidents", $payload)->assertStatus(403);

        $this->api()->postJson("/api/rider/jobs/{$parcel->id}/incidents", ['type' => 'damaged'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->api()->postJson("/api/rider/jobs/{$parcel->id}/incidents", $payload)
            ->assertCreated()->assertJsonPath('data.type', 'lost')->assertJsonPath('data.parcel_id', $parcel->id);

        // A second report while one is open is refused, as on the web.
        $this->api()->postJson("/api/rider/jobs/{$parcel->id}/incidents", $payload)->assertUnprocessable();
        $this->assertSame(1, Incidents::query()->count());
    }

    public function test_notifications_are_scoped_to_the_token_owner(): void
    {
        $mine = $this->notify($this->rider, ['type' => 'parcel_assigned', 'title' => 'New delivery', 'message' => 'Parcel #7 assigned.', 'seller_order_id' => 7, 'url' => '/rider/deliveries']);
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $theirs = $this->notify($other, ['title' => 'Not yours', 'message' => 'Hidden']);

        $this->api()->getJson('/api/rider/notifications')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine->id)
            ->assertJsonPath('data.data.0.seller_order_id', 7)
            ->assertJsonMissingPath('data.data.0.url')
            ->assertJsonPath('data.meta.unread_count', 1);

        $this->api()->postJson("/api/rider/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);

        $this->api()->postJson("/api/rider/notifications/{$mine->id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_history_lists_my_finished_deliveries_with_their_credited_earning(): void
    {
        $delivered = $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);
        RiderEarning::query()->forceCreate([
            'courier_id' => $this->courier->id,
            'logistics_provider_id' => $this->provider->id,
            'seller_order_id' => $delivered->id,
            'job' => RiderEarning::DELIVERY,
            'amount_minor' => 6000,
            'earned_at' => now(),
        ]);
        $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $other->id]);

        $this->api()->getJson('/api/rider/history')->assertOk()
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.id', $delivered->id)
            ->assertJsonPath('data.history.0.earning_minor', 6000)
            ->assertJsonPath('data.history.0.buyer_phone', null);
    }

    public function test_earnings_totals_are_sums_of_credited_rows(): void
    {
        $parcel = $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);
        foreach ([[RiderEarning::DELIVERY, 6000, now()], [RiderEarning::PICKUP, 2500, now()->subWeeks(3)]] as [$job, $amount, $at]) {
            RiderEarning::query()->forceCreate([
                'courier_id' => $this->courier->id,
                'logistics_provider_id' => $this->provider->id,
                'seller_order_id' => $parcel->id,
                'job' => $job,
                'amount_minor' => $amount,
                'earned_at' => $at,
            ]);
        }

        $this->api()->getJson('/api/rider/earnings?limit=5')->assertOk()
            ->assertJsonPath('data.totals.today_minor', 6000)
            ->assertJsonPath('data.totals.week_minor', 6000)
            ->assertJsonPath('data.totals.all_time_minor', 8500)
            ->assertJsonPath('data.totals.jobs', 2)
            ->assertJsonCount(2, 'data.recent');
    }

    public function test_provider_list_matches_the_registration_rule(): void
    {
        LogisticsProvider::factory()->create(['status' => 'pending', 'business_name' => 'Pending Co']);
        LogisticsProvider::factory()->create(['status' => 'approved', 'archived_at' => now(), 'business_name' => 'Archived Co']);

        $names = collect($this->getJson('/api/rider/providers')->assertOk()->json('data.providers'))->pluck('name');

        $this->assertTrue($names->contains($this->provider->business_name));
        $this->assertFalse($names->contains('Pending Co'));
        $this->assertFalse($names->contains('Archived Co'));
    }

    public function test_provider_list_narrows_to_companies_covering_the_barangay(): void
    {
        // $this->provider covers the Quezon City fixture barangay (RiderTestCase).
        $cebu = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'business_name' => 'Cebu Couriers']);
        $this->coverMunicipality($cebu, 'Cebu City');
        $quezonCity = $this->fixtureBarangay('Quezon City');

        $names = collect($this->getJson('/api/rider/providers?barangay='.$quezonCity)->assertOk()
            ->assertJsonPath('data.barangay', $quezonCity)->json('data.providers'))->pluck('name');
        $this->assertTrue($names->contains($this->provider->business_name));
        $this->assertFalse($names->contains('Cebu Couriers'));

        // Uncovered barangay: nothing to offer. No barangay: the full web list.
        $this->getJson('/api/rider/providers?barangay=0999999999')->assertOk()->assertJsonCount(0, 'data.providers');
        $this->assertTrue(collect($this->getJson('/api/rider/providers')->json('data.providers'))->pluck('name')->contains('Cebu Couriers'));
        $this->getJson('/api/rider/providers?barangay=not-a-code')->assertUnprocessable();
    }

    /** @param array<string, mixed> $data */
    private function notify(Rider $rider, array $data): DatabaseNotification
    {
        return $rider->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Rider\\Test',
            'data' => $data,
        ]);
    }
}
