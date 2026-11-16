<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Admin\BuyerReturnHistoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Read-only Buyer Return History panel on the Admin returns directory. */
class BuyerReturnHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** One Seller's shipment within a checkout, through the given statuses, logged in the status history. */
    private function shipment(Order $order, OrderStatus ...$path): SellerOrder
    {
        $parcel = SellerOrder::factory()->create(['order_id' => $order->id, 'seller_id' => Seller::factory()->create(['status' => 'approved'])->id]);
        $from = OrderStatus::OutForDelivery;
        foreach ($path as $status) {
            StatusHistory::query()->create(['seller_order_id' => $parcel->id, 'from_status' => $from, 'to_status' => $status, 'actor_type' => 'rider', 'source' => 'web']);
            $from = $status;
        }
        $parcel->forceFill(['status' => ($path === [] ? OrderStatus::InTransit : end($path))->value])->save();

        return $parcel;
    }

    /** @param array<string, mixed> $attributes */
    private function returnFor(SellerOrder $parcel, array $attributes = []): ParcelReturn
    {
        $record = new ParcelReturn;
        $record->forceFill($attributes + [
            'seller_order_id' => $parcel->id, 'status' => ParcelReturnStatus::Requested, 'reason' => 'Item arrived damaged',
            'requested_at' => now(), 'seller_response_due_at' => now()->addDays(ParcelReturn::SELLER_DECISION_DAYS),
        ])->save();

        return $record;
    }

    /** @return array<int, array<string, mixed>> */
    private function histories(ParcelReturn ...$records): array
    {
        $loaded = ParcelReturn::query()->with('sellerOrder.order')->whereKey(array_map(fn (ParcelReturn $record) => $record->id, $records))->get();

        return app(BuyerReturnHistoryService::class)->forReturns($loaded);
    }

    /**
     * Buyer with two checkouts: three shipments in the first, three in the second.
     *
     * @return array<string, mixed>
     */
    private function busyBuyer(): array
    {
        $buyer = Buyer::factory()->create();
        $first = Order::factory()->create(['buyer_id' => $buyer->id]);
        $second = Order::factory()->create(['buyer_id' => $buyer->id]);

        $current = $this->shipment($first, OrderStatus::Delivered);
        $upheld = $this->shipment($first, OrderStatus::Delivered, OrderStatus::Completed);
        $overturned = $this->shipment($first, OrderStatus::Delivered);
        $receipt = $this->shipment($second, OrderStatus::Delivered);
        $kept = $this->shipment($second, OrderStatus::Delivered, OrderStatus::Completed);
        $failed = $this->shipment($second, OrderStatus::DeliveryFailed);

        return [
            'buyer' => $buyer,
            'current' => $this->returnFor($current, ['status' => ParcelReturnStatus::InDispute, 'seller_decision' => ParcelReturn::REJECTED, 'escalated_at' => now()]),
            'upheld' => $this->returnFor($upheld, ['status' => ParcelReturnStatus::Rejected, 'seller_decision' => ParcelReturn::REJECTED, 'escalated_at' => now(), 'dispute_outcome' => ParcelReturn::DISPUTE_UPHELD]),
            'overturned' => $this->returnFor($overturned, ['status' => ParcelReturnStatus::AtOrigin, 'seller_decision' => ParcelReturn::REJECTED, 'escalated_at' => now(), 'dispute_outcome' => ParcelReturn::DISPUTE_OVERTURNED, 'receipt_disputed_at' => now()]),
            'receipt' => $this->returnFor($receipt, ['status' => ParcelReturnStatus::ReceiptDisputed, 'seller_decision' => ParcelReturn::APPROVED, 'receipt_disputed_at' => now()]),
            // A failed delivery going back to the Seller is not a buyer request.
            'failed' => $this->returnFor($failed, ['status' => ParcelReturnStatus::InTransit, 'seller_response_due_at' => null, 'delivery_attempts' => 2]),
            'kept' => $kept,
        ];
    }

    public function test_buyer_with_no_other_history_gets_an_empty_state_without_a_rate(): void
    {
        $buyer = Buyer::factory()->create();
        $record = $this->returnFor($this->shipment(Order::factory()->create(['buyer_id' => $buyer->id]), OrderStatus::Delivered));

        $history = $this->histories($record)[$record->id];
        $this->assertSame(0, $history['delivered_shipments']);
        $this->assertSame(0, $history['return_requests']);
        $this->assertNull($history['request_rate']);
        $this->assertTrue($history['recent']->isEmpty());

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->get(route('admin.returns.index'))->assertOk()
            ->assertSee('Buyer return history')
            ->assertSee('No other return requests by this buyer.')
            ->assertSee('No other delivered shipments on record.')
            ->assertSee(route('admin.users.index', ['type' => 'buyer', 'search' => $buyer->email]));
    }

    public function test_counts_use_shipments_across_checkouts_and_exclude_the_current_case(): void
    {
        $f = $this->busyBuyer();

        $history = $this->histories($f['current'])[$f['current']->id];
        // Five delivered shipments (Completed ones included), minus the current one; the failed delivery never reached Delivered.
        $this->assertSame(4, $history['delivered_shipments']);
        // Three other buyer requests; the current case and the failed-delivery return are not counted.
        $this->assertSame(3, $history['return_requests']);
        $this->assertSame(75.0, $history['request_rate']);
        $this->assertSame(2, $history['seller_rejected']);
        $this->assertSame(1, $history['dispute_upheld']);
        $this->assertSame(1, $history['dispute_overturned']);
        // The current case is in dispute, but it is not a prior dispute.
        $this->assertSame(0, $history['dispute_open']);
        $this->assertSame(2, $history['receipt_disputes']);
        $this->assertSame(1, $history['receipt_disputes_open']);
        $this->assertSame([$f['receipt']->id, $f['overturned']->id, $f['upheld']->id], $history['recent']->pluck('id')->all());
    }

    public function test_viewing_another_case_counts_the_first_one_as_history(): void
    {
        $f = $this->busyBuyer();

        $history = $this->histories($f['upheld'])[$f['upheld']->id];
        $this->assertSame(4, $history['delivered_shipments']);
        $this->assertSame(3, $history['return_requests']);
        $this->assertSame(0, $history['dispute_upheld']);
        $this->assertSame(1, $history['dispute_overturned']);
        $this->assertSame(1, $history['dispute_open']);
        $this->assertNotContains($f['upheld']->id, $history['recent']->pluck('id')->all());
    }

    public function test_failed_delivery_returns_get_no_buyer_history(): void
    {
        $f = $this->busyBuyer();

        $this->assertArrayNotHasKey($f['failed']->id, $this->histories($f['failed']));
    }

    public function test_histories_are_isolated_between_buyers(): void
    {
        $f = $this->busyBuyer();
        $other = Buyer::factory()->create();
        $otherRecord = $this->returnFor($this->shipment(Order::factory()->create(['buyer_id' => $other->id]), OrderStatus::Delivered));

        $histories = $this->histories($f['current'], $otherRecord);
        $this->assertSame(0, $histories[$otherRecord->id]['return_requests']);
        $this->assertSame(0, $histories[$otherRecord->id]['delivered_shipments']);
        $this->assertSame(3, $histories[$f['current']->id]['return_requests']);
        $this->assertNotContains($otherRecord->id, $histories[$f['current']->id]['recent']->pluck('id')->all());
    }

    public function test_query_count_does_not_grow_with_buyers_or_returns(): void
    {
        $f = $this->busyBuyer();
        $loaded = ParcelReturn::query()->with('sellerOrder.order')->whereKey($f['current']->id)->get();
        DB::enableQueryLog();
        app(BuyerReturnHistoryService::class)->forReturns($loaded);
        $single = count(DB::getQueryLog());

        foreach (range(1, 4) as $ignored) {
            $buyer = Buyer::factory()->create();
            $order = Order::factory()->create(['buyer_id' => $buyer->id]);
            $this->returnFor($this->shipment($order, OrderStatus::Delivered));
            $this->returnFor($this->shipment($order, OrderStatus::Delivered), ['status' => ParcelReturnStatus::Rejected, 'seller_decision' => ParcelReturn::REJECTED]);
        }
        $loaded = ParcelReturn::query()->with('sellerOrder.order')->get();
        DB::flushQueryLog();
        app(BuyerReturnHistoryService::class)->forReturns($loaded);

        $this->assertSame($single, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(4, $single);
    }

    public function test_admin_page_shows_the_panel_and_links_to_earlier_records(): void
    {
        $f = $this->busyBuyer();
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index', ['return' => $f['current']->id]))->assertOk()
            ->assertSee('Return #'.$f['current']->id.' only')
            ->assertSee('Historical request rate (requests per delivered shipment)')
            ->assertSee('75%')
            ->assertSee(route('admin.returns.index', ['return' => $f['upheld']->id]), false)
            ->assertSee('dispute rejection kept')
            ->assertSee('dispute return approved')
            ->assertSee('not a finding against the buyer')
            ->assertDontSee('Return #'.$f['current']->id.'</a>', false);

        // The single-record filter shows only that return.
        $this->actingAs($admin, 'admin')->get(route('admin.returns.index', ['return' => $f['upheld']->id]))->assertOk()
            ->assertViewHas('returns', fn ($returns) => $returns->pluck('id')->all() === [$f['upheld']->id]);
    }

    public function test_only_admins_can_see_the_returns_directory(): void
    {
        $f = $this->busyBuyer();

        $this->get(route('admin.returns.index'))->assertRedirect();
        $this->actingAs($f['buyer'], 'buyer')->get(route('admin.returns.index'))->assertRedirect();
    }
}
