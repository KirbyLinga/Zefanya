<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BuyerOrderReviewRedirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function purchase(Order $order, OrderStatus $status, ?Product $product = null): OrderItem
    {
        $product ??= Product::factory()->create();
        $shipment = SellerOrder::factory()->create([
            'order_id' => $order->id, 'seller_id' => $product->seller_id, 'status' => $status->value,
        ]);

        return OrderItem::factory()->create([
            'seller_order_id' => $shipment->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'quantity' => 1,
        ]);
    }

    public function test_selected_receipt_redirects_directly_to_its_editable_review_form(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $item = $this->purchase($order, OrderStatus::Delivered);
        $sibling = $this->purchase($order, OrderStatus::Completed);
        $url = route('buyer.orders.review', ['order' => $order, 'seller_order_id' => $item->seller_order_id]);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $order), [
            'seller_order_id' => $item->seller_order_id,
        ])->assertRedirect($url)->assertSessionHas('success', 'Order received.');
        $this->assertSame(OrderStatus::Completed, $item->sellerOrder->fresh()->status);
        $this->get($url)->assertOk()->assertSee('Review your order')
            ->assertSee('name="rating"', false)->assertSee('name="photos[]"', false)
            ->assertSee(route('buyer.products.reviews.store', $item->product), false)
            ->assertViewHas('reviewItems', fn ($items) => $items->pluck('id')->all() === [$item->id]);
    }

    public function test_order_receipt_reviews_all_eligible_items_but_excludes_in_transit_and_returned_shipments(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $first = $this->purchase($order, OrderStatus::Delivered);
        $second = $this->purchase($order, OrderStatus::Delivered);
        $this->purchase($order, OrderStatus::InTransit);
        $this->purchase($order, OrderStatus::Returned);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect(route('buyer.orders.review', $order));
        $this->get(route('buyer.orders.review', $order))->assertOk()
            ->assertViewHas('reviewItems', fn ($items) => $items->pluck('id')->all() === [$first->id, $second->id]);
    }

    public function test_review_scope_rejects_foreign_orders_and_shipments_and_requires_buyer_authentication(): void
    {
        $buyer = Buyer::factory()->create();
        $own = Order::factory()->create(['buyer_id' => $buyer->id]);
        $foreign = Order::factory()->create();
        $item = $this->purchase($foreign, OrderStatus::Completed);
        $this->get(route('buyer.orders.review', $own))->assertRedirect();
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.review', $foreign))->assertNotFound();
        $this->get(route('buyer.orders.review', ['order' => $own, 'seller_order_id' => $item->seller_order_id]))->assertNotFound();
        $this->get(route('buyer.orders.review', ['order' => $own, 'seller_order_id' => 0]))->assertSessionHasErrors('seller_order_id');
    }

    public function test_submitting_one_purchase_returns_to_remaining_forms_and_does_not_duplicate_reviews(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $first = $this->purchase($order, OrderStatus::Completed);
        $second = OrderItem::factory()->create([
            'seller_order_id' => $first->seller_order_id, 'product_id' => $first->product_id,
            'product_name' => 'Second purchased item',
        ]);
        $url = route('buyer.orders.review', $order);
        $payload = ['order_item_id' => $first->id, 'rating' => 4, 'body' => 'Arrived safely.'];

        $this->actingAs($buyer, 'buyer')->from($url)->post(route('buyer.products.reviews.store', $first->product), $payload)
            ->assertRedirect($url)->assertSessionHas('success', 'Your review has been posted.');
        $this->get($url)->assertOk()->assertViewHas('reviewItems', fn ($items) => $items->pluck('id')->all() === [$second->id]);
        $this->from($url)->post(route('buyer.products.reviews.store', $first->product), $payload)
            ->assertSessionHasErrors('order_item_id');
        $this->assertDatabaseCount('reviews', 1);
        $this->from($url)->post(route('buyer.products.reviews.store', $first->product), [
            'order_item_id' => $second->id, 'rating' => 5,
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('No items to review')->assertDontSee('name="rating"', false);
        $this->assertDatabaseCount('reviews', 2);
    }

    public function test_deleted_or_missing_products_and_reviewed_items_do_not_render_broken_forms(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $deleted = $this->purchase($order, OrderStatus::Completed);
        $deleted->product->delete();
        $reviewed = $this->purchase($order, OrderStatus::Completed);
        Review::query()->create(['buyer_id' => $buyer->id, 'product_id' => $reviewed->product_id, 'order_item_id' => $reviewed->id, 'rating' => 5]);
        OrderItem::factory()->create(['seller_order_id' => $reviewed->seller_order_id, 'product_id' => null]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.review', $order))
            ->assertOk()->assertSee('No items to review')->assertDontSee('name="rating"', false);
    }

    public function test_invalid_confirmation_keeps_existing_error_flow(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $item = $this->purchase($order, OrderStatus::InTransit);
        $origin = route('buyer.orders.show', $order);
        $this->actingAs($buyer, 'buyer')->from($origin)->post(route('buyer.orders.confirmReceived', $order), [
            'seller_order_id' => $item->seller_order_id,
        ])->assertRedirect($origin)->assertSessionHasErrors('shipment');
        $this->assertSame(OrderStatus::InTransit, $item->sellerOrder->fresh()->status);
    }

    public function test_validation_and_old_input_are_scoped_to_the_submitted_purchase(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $first = $this->purchase($order, OrderStatus::Completed);
        $this->purchase($order, OrderStatus::Completed);
        $url = route('buyer.orders.review', $order);
        $body = str_repeat('a', Review::MAX_BODY_LENGTH + 1);
        $this->actingAs($buyer, 'buyer')->from($url)->post(route('buyer.products.reviews.store', $first->product), [
            'order_item_id' => $first->id, 'rating' => 3, 'body' => $body,
        ])->assertRedirect($url)->assertSessionHasErrors('body');
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, $body));
        $this->assertSame(1, preg_match_all('/type="radio"[^>]*checked/', $html));
    }

    public function test_review_page_uses_the_purchased_variant_snapshot(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $item = $this->purchase($order, OrderStatus::Completed);
        $item->update(['variant_options' => [
            ['name' => 'Color', 'value' => 'Rose'],
            ['name' => 'Size', 'value' => 'Medium'],
        ]]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.review', $order))
            ->assertOk()->assertSee('Color: Rose / Size: Medium')
            ->assertSee('name="rating"', false);
    }
}
