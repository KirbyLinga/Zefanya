<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Rider\RiderPayoutRecorded;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderEarningService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RiderEarningsTest extends TestCase
{
    use RefreshDatabase;

    private LogisticsProvider $provider;

    private Rider $rider;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Test Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Test Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Santo Rosario',
            'street' => '1 Test Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        $this->rider = Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
            'station_hub_id' => $station->id,
        ]);

        $this->courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'rider_id' => $this->rider->id,
            'name' => 'Juan Rider',
            'is_available' => true,
        ]);
    }

    private function parcel(OrderStatus $status, array $couriers): SellerOrder
    {
        $order = SellerOrder::factory()->create();
        $order->forceFill(['status' => $status->value] + $couriers)->save();

        return $order->fresh();
    }

    public function test_a_successful_pickup_pays_thirty_pesos_once(): void
    {
        $order = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        app(SellerOrderStatusService::class)->transition($order, OrderStatus::PickedUp);
        app(SellerOrderStatusService::class)->transition($order->fresh(), OrderStatus::PickedUp);

        $earning = RiderEarning::query()->sole();
        $this->assertSame(RiderEarning::PICKUP, $earning->job);
        $this->assertSame(3000, $earning->amount_minor);
        $this->assertSame($this->courier->id, $earning->courier_id);
        $this->assertSame($this->provider->id, $earning->logistics_provider_id);
    }

    public function test_a_failed_delivery_pays_nothing_and_the_successful_retry_pays_fifty_pesos(): void
    {
        $other = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $order = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $other->id]);
        $status = app(SellerOrderStatusService::class);

        $status->transition($order, OrderStatus::DeliveryFailed);
        $this->assertSame(0, RiderEarning::query()->count());

        $order->fresh()->forceFill(['status' => OrderStatus::OutForDelivery->value, 'delivery_courier_id' => $this->courier->id])->save();
        $status->transition($order->fresh(), OrderStatus::Delivered);

        $earning = RiderEarning::query()->sole();
        $this->assertSame(RiderEarning::DELIVERY, $earning->job);
        $this->assertSame(5000, $earning->amount_minor);
        $this->assertSame($this->courier->id, $earning->courier_id);
        $this->assertSame(0, RiderEarning::query()->where('courier_id', $other->id)->count());
    }

    public function test_rider_dashboard_and_logistics_earnings_show_rider_pay(): void
    {
        $status = app(SellerOrderStatusService::class);
        $status->transition($this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]), OrderStatus::PickedUp);
        $status->transition($this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]), OrderStatus::Delivered);

        $this->actingAs($this->rider, 'rider')->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Pending pay')
            ->assertSee('₱80.00')
            ->assertViewHas('stats', fn ($stats) => $stats['earnings']['pendingMinor'] === 8000 && $stats['earnings']['paidMinor'] === 0);

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()
            ->assertSee('Rider pay owed: ₱80.00')
            ->assertSee('Juan Rider');
    }

    private function payRider(int $throughId, int $expected, string $reference = 'GCASH-77', ?Courier $courier = null)
    {
        return $this->actingAs($this->provider, 'logistics')
            ->from(route('logistics.earnings.index'))
            ->post(route('logistics.earnings.pay-rider', $courier ?? $this->courier), [
                'through_id' => $throughId,
                'expected_amount_minor' => $expected,
                'reference' => $reference,
                'paid_on' => now()->toDateString(),
            ]);
    }

    public function test_a_company_pays_a_batch_of_earnings_once_and_the_rider_sees_it_as_paid(): void
    {
        $status = app(SellerOrderStatusService::class);
        $status->transition($this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]), OrderStatus::PickedUp);
        $status->transition($this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]), OrderStatus::Delivered);
        $throughId = (int) RiderEarning::query()->max('id');

        // A job finished after the page loaded is left for the next payment.
        $status->transition($this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]), OrderStatus::Delivered);

        $this->payRider($throughId, 8000)->assertSessionHasNoErrors()->assertSessionHas('success');

        $payout = RiderPayout::query()->sole();
        $this->assertSame(8000, $payout->amount_minor);
        $this->assertSame(2, $payout->earning_count);
        $this->assertSame('GCASH-77', $payout->reference);
        $this->assertSame(RiderPayout::PAID, $payout->status);
        $this->assertSame(2, RiderEarning::query()->where('rider_payout_id', $payout->id)->count());
        $this->assertSame(1, RiderEarning::query()->whereNull('rider_payout_id')->count());

        // Replaying the same form cannot pay those earnings again.
        $this->payRider($throughId, 8000)->assertSessionHasErrors('rider');
        $this->assertSame(1, RiderPayout::query()->count());

        $this->actingAs($this->rider, 'rider')->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Paid to you')
            ->assertViewHas('stats', fn ($stats) => $stats['earnings']['pendingMinor'] === 5000 && $stats['earnings']['paidMinor'] === 8000);
    }

    public function test_a_changed_amount_or_another_companys_rider_cannot_be_paid(): void
    {
        app(SellerOrderStatusService::class)->transition($this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]), OrderStatus::PickedUp);
        $throughId = (int) RiderEarning::query()->max('id');

        $this->payRider($throughId, 9999)->assertSessionHasErrors('rider');

        $outsider = Courier::factory()->create(['logistics_provider_id' => LogisticsProvider::factory()->create()->id]);
        $this->payRider($throughId, 3000, courier: $outsider)->assertNotFound();

        $this->assertSame(0, RiderPayout::query()->count());
    }

    private function payableJob(): RiderEarning
    {
        app(SellerOrderStatusService::class)->transition(
            $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]), OrderStatus::PickedUp,
        );

        return RiderEarning::query()->latest('id')->firstOrFail();
    }

    private function reversePayment(RiderPayout $payout, array $data = [])
    {
        return $this->actingAs($this->provider, 'logistics')->from(route('logistics.earnings.index'))
            ->post(route('logistics.earnings.reverse-rider-payment', $payout), $data + [
                'reason' => 'Incorrect bank payment reference recorded.', 'confirm_reversal' => '1',
            ]);
    }

    public function test_rider_payment_and_reversal_reach_only_its_recipient_with_payment_details(): void
    {
        $earning = $this->payableJob();
        $this->payRider($earning->id, 3000)->assertSessionHasNoErrors();
        $payout = RiderPayout::query()->sole();
        $notice = $this->rider->notifications()->where('type', RiderPayoutRecorded::class)->sole();
        $this->assertSame($payout->id, $notice->data['payout_id']);
        $this->assertSame(3000, $notice->data['amount_minor']);
        $this->assertSame('GCASH-77', $notice->data['reference']);
        $this->assertSame(now()->toDateString(), $notice->data['paid_on']);
        $this->assertSame(route('rider.dashboard'), $notice->data['url']);
        $this->assertSame(1, DB::table('notifications')->where('type', RiderPayoutRecorded::class)->count());
        $this->actingAs($this->rider, 'rider')->getJson(route('rider.notifications.recent'))
            ->assertOk()->assertJsonPath('notifications.0.type', 'rider_payout_paid');

        $this->payRider($earning->id, 3000)->assertSessionHasErrors('rider');
        $this->assertSame(1, $this->rider->notifications()->where('type', RiderPayoutRecorded::class)->count());
        $this->reversePayment($payout)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->rider->notifications()->where('type', RiderPayoutRecorded::class)->count());
    }

    public function test_rider_reversal_preserves_original_and_audits_allocations_then_allows_replacement(): void
    {
        $earning = $this->payableJob();
        $this->payRider($earning->id, 3000)->assertSessionHasNoErrors();
        $original = RiderPayout::query()->sole();
        $attributes = $original->getAttributes();

        $this->reversePayment($original)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($attributes, $original->fresh()->getAttributes());
        $this->assertNull($earning->fresh()->rider_payout_id);
        $log = $original->fresh()->reversal;
        $this->assertSame('logistics', $log->actor_type);
        $this->assertSame($this->provider->id, $log->actor_id);
        $this->assertSame('Incorrect bank payment reference recorded.', $log->reason);
        $this->assertSame((string) $earning->id, $log->old_values['earning_ids']);
        $this->assertSame('GCASH-77', $log->old_values['reference']);
        $this->assertSame(['pendingMinor' => 3000, 'paidMinor' => 0],
            array_intersect_key(app(RiderEarningService::class)->summaryFor($this->courier), array_flip(['pendingMinor', 'paidMinor'])));
        $this->assertSame(0, app(RiderEarningService::class)->paidThisMonthMinor($this->provider));
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()->assertSee('Reversed')->assertSee($log->reason)->assertSee('GCASH-77');

        $this->payRider($earning->id, 3000, 'GCASH-CORRECT')->assertSessionHasNoErrors();
        $this->assertSame(2, RiderPayout::query()->count());
        $this->assertNotSame($original->id, $earning->fresh()->rider_payout_id);
        $this->assertSame(3000, app(RiderEarningService::class)->paidThisMonthMinor($this->provider));
        $this->reversePayment($original)->assertSessionHasErrors('payout');
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'rider_payout_reversed')->count());
        $this->assertSame($attributes, $original->fresh()->getAttributes());
    }

    public function test_rider_correction_requires_reason_confirmation_and_owning_company(): void
    {
        $earning = $this->payableJob();
        $this->payRider($earning->id, 3000)->assertSessionHasNoErrors();
        $payout = RiderPayout::query()->sole();
        foreach (['', 'short', str_repeat('x', 501)] as $reason) {
            $this->reversePayment($payout, ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->reversePayment($payout, ['confirm_reversal' => '0'])->assertSessionHasErrors('confirm_reversal');
        $foreign = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($foreign, 'logistics')->post(route('logistics.earnings.reverse-rider-payment', $payout), [
            'reason' => 'Incorrect bank payment reference recorded.', 'confirm_reversal' => '1',
        ])->assertNotFound();
        $this->assertSame($payout->id, $earning->fresh()->rider_payout_id);
        $this->assertNull($payout->fresh()->reversal);
    }

    public function test_changed_rider_allocations_stop_reversal_without_partial_history(): void
    {
        $earning = $this->payableJob();
        $this->payRider($earning->id, 3000)->assertSessionHasNoErrors();
        $payout = RiderPayout::query()->sole();
        $earning->forceFill(['amount_minor' => 1000])->save();
        $this->reversePayment($payout)->assertSessionHasErrors('payout');
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $earning->fresh()->rider_payout_id);
    }

    public function test_rolled_back_rider_payment_never_notifies_or_keeps_allocations(): void
    {
        $earning = $this->payableJob();
        Notification::fake();
        try {
            DB::transaction(function () use ($earning): void {
                app(RiderEarningService::class)->payCourier($this->provider, $this->courier, $earning->id, 3000, 'ROLLBACK', now()->toDateString());
                Notification::assertNothingSent();
                throw new \RuntimeException('Rollback the payment');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback the payment', $exception->getMessage());
        }
        $this->assertSame(0, RiderPayout::query()->count());
        $this->assertNull($earning->fresh()->rider_payout_id);
        Notification::assertNothingSent();
    }

    public function test_rolled_back_rider_reversal_restores_the_original_paid_state(): void
    {
        $earning = $this->payableJob();
        $this->payRider($earning->id, 3000)->assertSessionHasNoErrors();
        $payout = RiderPayout::query()->sole();
        Notification::fake();
        try {
            DB::transaction(function () use ($payout): void {
                app(RiderEarningService::class)->reversePayout($this->provider, $payout, 'Wrong payment reference was entered.');
                throw new \RuntimeException('Rollback the correction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback the correction', $exception->getMessage());
        }
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $earning->fresh()->rider_payout_id);
        Notification::assertNothingSent();
    }

    public function test_guests_and_riders_cannot_reverse_company_payment_records(): void
    {
        $earning = $this->payableJob();
        app(RiderEarningService::class)->payCourier($this->provider, $this->courier, $earning->id, 3000, 'AUTH-CHECK', now()->toDateString());
        $payout = RiderPayout::query()->sole();
        $url = route('logistics.earnings.reverse-rider-payment', $payout);
        $this->post($url)->assertRedirect();
        $this->actingAs($this->rider, 'rider')->post($url)->assertRedirect();
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $earning->fresh()->rider_payout_id);
    }

    private function cash(Courier $courier, int $amount, string $status, ?\DateTimeInterface $createdAt = null): CodRemittance
    {
        $order = SellerOrder::factory()->create();
        $cash = new CodRemittance;
        $cash->forceFill([
            'payment_id' => Payment::factory()->create(['seller_order_id' => $order->id])->id,
            'seller_order_id' => $order->id,
            'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id,
            'amount_minor' => $amount,
            'status' => $status,
            'received_amount_minor' => $status === CodRemittance::REMITTED_TO_LOGISTICS ? $amount : null,
            'confirmed_at' => $status === CodRemittance::REMITTED_TO_LOGISTICS ? now() : null,
            'created_at' => $createdAt ?? now(),
        ])->save();

        return $cash;
    }

    public function test_the_rider_money_page_shows_cash_held_cash_handed_over_and_pay(): void
    {
        $other = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $this->cash($this->courier, 123400, CodRemittance::PENDING_REMITTANCE, now()->subDays(5));
        $this->cash($this->courier, 50000, CodRemittance::PENDING_REMITTANCE);
        $this->cash($this->courier, 80000, CodRemittance::REMITTED_TO_LOGISTICS);
        $this->cash($other, 999900, CodRemittance::PENDING_REMITTANCE);

        $pickup = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        app(SellerOrderStatusService::class)->transition($pickup, OrderStatus::PickedUp);

        $summary = app(RiderCashService::class)->summaryFor($this->courier);
        $this->assertSame(173400, $summary['holdingMinor']);
        $this->assertSame(2, $summary['holdingCount']);
        $this->assertSame(1, $summary['overdueCount']);
        $this->assertSame(80000, $summary['handedOverMinor']);

        $this->actingAs($this->rider, 'rider')->get(route('rider.money.index'))
            ->assertOk()
            ->assertSee('My Money')
            ->assertSee('₱1,734.00')
            ->assertSee('Cash to hand over (2)')
            ->assertSee('1 parcel has been with you for more than 3 days')
            ->assertSee('Overdue')
            ->assertSee('Cash you handed over')
            ->assertSee('₱800.00')
            ->assertSee('Pickup earnings')
            ->assertSee('Delivery earnings')
            ->assertSee('+₱30.00')
            ->assertSee('Pending pay')
            ->assertSee('₱30.00')
            ->assertSee('Pending')
            ->assertDontSee('₱9,999.00');

        $this->get(route('rider.dashboard'))->assertOk()
            ->assertSee('You are holding ₱1,734.00 in delivery cash from 2 parcels.')
            ->assertSee('Hand it over');
    }

    public function test_the_rider_money_page_shows_paid_jobs_with_their_reference_and_reversals(): void
    {
        $pickup = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        app(SellerOrderStatusService::class)->transition($pickup, OrderStatus::PickedUp);
        $earning = RiderEarning::query()->sole();
        app(RiderEarningService::class)->payCourier($this->provider, $this->courier, $earning->id, 3000, 'GCASH-RIDER-1', now()->toDateString());

        $this->actingAs($this->rider, 'rider')->get(route('rider.money.index'))
            ->assertOk()->assertSee('Ref GCASH-RIDER-1')->assertSee('Payments recorded for you')->assertDontSee('Reversed for correction');

        app(RiderEarningService::class)->reversePayout($this->provider, RiderPayout::query()->sole(), 'Wrong reference typed');

        $this->get(route('rider.money.index'))->assertOk()->assertSee('Reversed for correction')->assertSee('Pending');
    }

    public function test_a_rider_with_no_cash_sees_empty_states_and_guests_are_sent_to_login(): void
    {
        $this->actingAs($this->rider, 'rider')->get(route('rider.money.index'))
            ->assertOk()->assertSee('You are not holding any delivery cash.')->assertSee('Complete a pickup or delivery to start earning.');
        $this->get(route('rider.dashboard'))->assertDontSee('in delivery cash from');

        auth('rider')->logout();
        $this->get(route('rider.money.index'))->assertRedirect();
    }
}
