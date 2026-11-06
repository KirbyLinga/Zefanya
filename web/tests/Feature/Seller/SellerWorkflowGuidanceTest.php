<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class SellerWorkflowGuidanceTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase;

    public function test_dashboard_prompts_a_seller_without_shipping_to_enable_a_provider(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('shippingReady', false)
            ->assertSee('Enable Shipping Provider')
            ->assertSee(route('seller.shipping-providers.index'), false);
    }

    public function test_dashboard_hides_shipping_warning_only_while_an_enabled_provider_is_operational(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $provider = $this->bootstrapCheckoutCarriers(Buyer::factory()->create(), [$seller]);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('shippingReady', true)
            ->assertDontSee('Enable Shipping Provider');

        $provider->forceFill(['status' => 'suspended'])->save();

        $this->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('shippingReady', false)
            ->assertSee('Enable Shipping Provider');
    }

    public function test_another_sellers_enabled_shipping_does_not_hide_the_warning(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $other = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers(Buyer::factory()->create(), [$other]);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('shippingReady', false)
            ->assertSee('Enable Shipping Provider');
    }

    public function test_dashboard_reminds_only_its_seller_of_the_three_earliest_pending_return_deadlines(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 02:00:00', 'UTC'));
        $seller = Seller::factory()->create(['status' => 'approved']);
        $later = $this->returnRequest($seller, now()->addDays(2));
        $soon = $this->returnRequest($seller, now()->addHours(12));
        $overdue = $this->returnRequest($seller, now()->subHour());
        $next = $this->returnRequest($seller, now()->addHours(20));
        $this->returnRequest($seller, now()->addDays(3));
        $this->returnRequest($seller, now()->subDays(2), ParcelReturn::APPROVED);
        $foreign = $this->returnRequest(Seller::factory()->create(), now()->subDays(3));

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('pendingReturnDecisionCount', 5)
            ->assertViewHas('pendingReturnDecisions', fn ($records) => $records->pluck('id')->all() === [$overdue->id, $soon->id, $next->id])
            ->assertSee(route('seller.returns.index'), false)
            ->assertSee($soon->seller_response_due_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'))
            ->assertDontSee($foreign->seller_response_due_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'));

        $this->assertNull($overdue->fresh()->seller_decision);
        $this->assertNull($later->fresh()->seller_decision);
    }

    public function test_dashboard_has_no_return_reminders_for_answered_requests(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->returnRequest($seller, now()->addDay(), ParcelReturn::REJECTED);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('pendingReturnDecisionCount', 0)
            ->assertViewHas('pendingReturnDecisions', fn ($records) => $records->isEmpty());
    }

    public function test_pickup_plan_shows_recorded_window_rider_and_rescheduling_in_both_seller_screens(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $pickup = Courier::factory()->create(['name' => 'Pickup courier']);
        $delivery = Courier::factory()->create(['name' => 'Delivery courier']);
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create(['seller_id' => $seller->id]);
        $parcel->forceFill([
            'courier_id' => $pickup->id, 'delivery_courier_id' => $delivery->id,
            'pickup_window_starts_at' => Carbon::parse('2026-11-05 00:00:00', 'UTC'),
            'pickup_window_ends_at' => Carbon::parse('2026-11-05 04:00:00', 'UTC'),
            'pickup_missed_windows' => 2,
        ])->save();
        $this->actingAs($seller, 'seller');

        foreach ([route('seller.orders.show', $parcel), route('seller.shipments.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Nov 5, 2026 8:00 AM')
                ->assertSee('12:00 PM')
                ->assertSee('Philippine time')
                ->assertSee('Pickup courier')
                ->assertSee('Delivery courier')
                ->assertSee('Rescheduled 2 times');
        }
    }

    public function test_unassigned_ready_pickup_does_not_claim_a_rider_or_recorded_window(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create();

        $this->actingAs($parcel->seller, 'seller')->get(route('seller.orders.show', $parcel))
            ->assertOk()
            ->assertSee('Pickup window not available yet')
            ->assertSee('Awaiting Logistics assignment');
    }

    public function test_seller_can_still_cancel_when_ready_and_sees_why_it_is_unavailable_after_pickup(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create();
        $cancelAction = 'action="'.route('seller.orders.decline', $parcel).'"';

        $this->actingAs($parcel->seller, 'seller')->get(route('seller.orders.show', $parcel))
            ->assertOk()
            ->assertSee($cancelAction, false)
            ->assertSee('Cancellation is available until the rider confirms pickup.');

        $parcel->forceFill(['status' => OrderStatus::PickedUp])->save();

        $this->get(route('seller.orders.show', $parcel))
            ->assertOk()
            ->assertDontSee($cancelAction, false)
            ->assertSee('Cancellation is unavailable because the rider has confirmed pickup.');
    }

    public static function returnDeadlineStates(): array
    {
        return [
            'due in 24 hours' => [24, true, false],
            'due later' => [25, false, false],
            'deadline reached' => [0, true, true],
            'deadline passed' => [-1, true, true],
        ];
    }

    #[DataProvider('returnDeadlineStates')]
    public function test_return_deadline_urgency_and_available_decisions_match_the_cutoff(int $hours, bool $urgent, bool $overdue): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 02:00:00', 'UTC'));
        $seller = Seller::factory()->create(['status' => 'approved']);
        $record = $this->returnRequest($seller, now()->addHours($hours));

        $response = $this->actingAs($seller, 'seller')->get(route('seller.returns.index'))->assertOk()
            ->assertSee($record->seller_response_due_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'));

        if ($urgent) {
            $response->assertSee($overdue ? 'Overdue - automatic approval pending' : 'Urgent: due within 24 hours');
        } else {
            $response->assertDontSee('Urgent: due within 24 hours');
        }
        $approveAction = 'action="'.route('seller.returns.approve', $record).'"';
        if ($overdue) {
            $response->assertDontSee($approveAction, false);
        } else {
            $response->assertSee($approveAction, false);
        }
        $this->assertNull($record->fresh()->seller_decision);
    }

    private function returnRequest(Seller $seller, Carbon $due, ?string $decision = null): ParcelReturn
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['seller_id' => $seller->id]);
        $record = new ParcelReturn;
        $record->forceFill([
            'seller_order_id' => $parcel->id,
            'reason' => 'Item arrived damaged',
            'requested_at' => $due->copy()->subDays(ParcelReturn::SELLER_DECISION_DAYS),
            'seller_response_due_at' => $due,
            'seller_decision' => $decision,
        ])->save();

        return $record->fresh();
    }
}
