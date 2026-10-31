<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerTrackingPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function statuses(): array
    {
        return array_map(fn (OrderStatus $status) => [$status], OrderStatus::cases());
    }

    #[DataProvider('statuses')]
    public function test_each_shipment_displays_its_exact_status_without_combined_labels(OrderStatus $status): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        SellerOrder::factory()->status($status)->create(['order_id' => $order->id]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.index'));

        $response->assertOk()->assertSee('data-shipment-status="'.$status->value.'"', false)
            ->assertSeeText($status->label())->assertDontSeeText('To Receive /')->assertDontSeeText('Cancelled / Returned');
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::DeliveryFailed], true)) {
            $response->assertDontSee('aria-label="Delivery milestones"', false);
        } else {
            $response->assertSee('aria-label="Delivery milestones"', false)
                ->assertSeeTextInOrder(['Order placed', 'Ready for pickup', 'In transit', 'Delivered', 'Completed']);
        }
    }

    public function test_delivered_is_not_presented_as_completed(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        SellerOrder::factory()->status(OrderStatus::Delivered)->create(['order_id' => $order->id]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.index'))->assertOk();

        $response->assertSeeText('Order completion is still pending.');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $current = $xpath->query('//li[@aria-current="step"]');
        $this->assertSame(1, $current->length);
        $this->assertSame('Delivered', trim($current->item(0)->textContent));
    }

    public static function exceptionFilters(): array
    {
        return [['cancelled', OrderStatus::Cancelled], ['returned', OrderStatus::Returned], ['delivery_failed', OrderStatus::DeliveryFailed]];
    }

    #[DataProvider('exceptionFilters')]
    public function test_exception_filters_and_counts_are_distinct(string $tab, OrderStatus $expected): void
    {
        $buyer = Buyer::factory()->create();
        $orders = [];
        foreach ([OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::DeliveryFailed] as $status) {
            $order = Order::factory()->create(['buyer_id' => $buyer->id]);
            SellerOrder::factory()->status($status)->create(['order_id' => $order->id]);
            $orders[$status->value] = $order;
        }

        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.index', ['tab' => $tab]))->assertOk()
            ->assertViewHas('orders', fn ($items) => $items->modelKeys() === [$orders[$expected->value]->id])
            ->assertViewHas('counts', fn ($counts) => $counts['cancelled'] === 1 && $counts['returned'] === 1 && $counts['delivery_failed'] === 1);
    }

    public function test_mixed_shipments_keep_separate_progress_and_matching_filter_counts(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $placed = SellerOrder::factory()->status(OrderStatus::Placed)->create(['order_id' => $order->id]);
        $delivered = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['order_id' => $order->id]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.index'))->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts['to_ship'] === 1 && $counts['to_receive'] === 1 && $counts['completed'] === 0);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ([$placed->id => 'Order placed', $delivered->id => 'Delivered'] as $id => $milestone) {
            $current = $xpath->query('//div[@data-shipment-id="'.$id.'"]//li[@aria-current="step"]');
            $this->assertSame(1, $current->length);
            $this->assertSame($milestone, trim($current->item(0)->textContent));
        }
        foreach (['to_ship', 'to_receive'] as $tab) {
            $this->get(route('buyer.orders.index', ['tab' => $tab]))->assertSee($order->reference);
        }
    }
}
