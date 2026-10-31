<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\OrderDelivered;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function milestones(): array
    {
        return [
            [OrderStatus::Sorted, OrderStatus::AssignedToRider, 'Courier Assigned'],
            [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, 'Order Out for Delivery'],
            [OrderStatus::OutForDelivery, OrderStatus::Delivered, 'Order Delivered'],
            [OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed, 'Delivery Attempt Failed'],
            [OrderStatus::Placed, OrderStatus::Cancelled, 'Shipment Cancelled'],
        ];
    }

    private function parcel(OrderStatus $status = OrderStatus::OutForDelivery): SellerOrder
    {
        return SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id])->id,
            'status' => $status,
        ]);
    }

    #[DataProvider('milestones')]
    public function test_each_milestone_creates_one_notification_even_with_stale_and_fresh_replays(OrderStatus $from, OrderStatus $to, string $title): void
    {
        $parcel = $this->parcel($from);
        $stale = $parcel->fresh();
        $buyer = $parcel->order->buyer;
        $service = new SellerOrderStatusService;
        $service->transition($parcel, $to);
        $service->transition($stale, $to);
        $service->transition($parcel->fresh(), $to);

        $this->assertSame(1, $buyer->notifications()->count());
        $this->actingAs($buyer, 'buyer')->getJson(route('buyer.notifications.index'))
            ->assertOk()->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', $title)
            ->assertJsonPath('notifications.0.url', route('buyer.orders.show', $parcel->order_id));
    }

    public function test_a_rolled_back_status_change_leaves_no_notification(): void
    {
        $parcel = $this->parcel();
        DB::beginTransaction();
        try {
            (new SellerOrderStatusService)->transition($parcel, OrderStatus::Delivered);
            $this->assertSame(1, $parcel->order->buyer->notifications()->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, $parcel->order->buyer->notifications()->count());
    }

    public function test_read_opens_the_correct_order_and_lowers_count_without_deleting_history(): void
    {
        $first = $this->parcel();
        $buyer = $first->order->buyer;
        $second = SellerOrder::factory()->create(['order_id' => Order::factory()->create(['buyer_id' => $buyer->id])->id]);
        $buyer->notify(new OrderDelivered($first));
        $buyer->notify(new OrderDelivered($second));
        $notification = $buyer->notifications()->get()->firstWhere('data.seller_order_id', $first->id);

        $this->actingAs($buyer, 'buyer')->getJson(route('buyer.notifications.count'))->assertOk()->assertJsonPath('count', 2);
        $url = route('buyer.orders.show', $first->order_id);
        foreach ([1, 2] as $attempt) {
            $this->postJson(route('buyer.notifications.markRead', $notification->id))
                ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('url', $url);
        }
        $this->get($url)->assertOk()->assertViewHas('order', fn ($order) => $order->id === $first->order_id);
        $this->getJson(route('buyer.notifications.index'))->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.url', route('buyer.orders.show', $second->order_id));
        $this->postJson(route('buyer.notifications.markAllRead'))->assertOk()->assertJsonPath('count', 0);
        $this->getJson(route('buyer.notifications.count'))->assertJsonPath('count', 0);
        $this->assertSame(2, $buyer->notifications()->count());
    }

    public function test_buyers_cannot_read_anothers_notification_or_follow_a_foreign_order_link(): void
    {
        $parcel = $this->parcel();
        $owner = $parcel->order->buyer;
        $owner->notify(new OrderDelivered($parcel));
        $foreign = $owner->notifications()->sole();
        $buyer = Buyer::factory()->create();
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.notifications.markRead', $foreign->id))->assertNotFound();
        $this->getJson(route('buyer.notifications.index'))->assertJsonCount(0, 'notifications');
        $buyer->notify(new OrderDelivered($parcel));
        $own = $buyer->notifications()->sole();
        $this->getJson(route('buyer.notifications.index'))->assertJsonPath('notifications.0.url', null);
        $this->postJson(route('buyer.notifications.markRead', $own->id))->assertOk()->assertJsonPath('url', null);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_guests_cannot_list_or_clear_notifications(): void
    {
        $this->getJson(route('buyer.notifications.index'))->assertUnauthorized();
        $this->getJson(route('buyer.notifications.count'))->assertUnauthorized();
        $this->postJson(route('buyer.notifications.markRead', 'unknown'))->assertUnauthorized();
        $this->postJson(route('buyer.notifications.markAllRead'))->assertUnauthorized();
    }
}
