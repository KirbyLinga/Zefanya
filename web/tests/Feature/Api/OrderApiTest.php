<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/orders` — the buyer's own parcels.
 *
 * The first authenticated *read* on this API, so two things are asserted that the
 * public routes never needed: that a stranger with no token gets nothing, and that
 * one buyer's token cannot reach another buyer's history. The rest is the shape the
 * app's `Order` reads, plus the two facts that are easy to get quietly wrong —
 * centavos, and the product ids the app's joins depend on.
 *
 * The rows are built directly rather than through factories, because no order
 * factories exist and inventing them would mean guessing at money rules the checkout
 * owns. What is asserted here is what the endpoint *reads*, not how an order is
 * written.
 */
class OrderApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A buyer with one order from one shop, and the rows that make it up.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $lines  name, quantity, price in centavos
     */
    private function orderFor(Buyer $buyer, string $shopName = 'Loom & Larder', array $lines = [['Handwoven Rattan Tote', 1, 125075]]): Order
    {
        $seller = Seller::factory()->create([
            'business_name' => $shopName,
            'status' => 'approved',
        ]);

        $subtotal = array_sum(array_map(fn (array $line): int => $line[1] * $line[2], $lines));

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['full' => '12-A Sanciangko Street, Lahug, Cebu City'],
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => $subtotal,
        ]);

        foreach ($lines as [$name, $quantity, $priceMinor]) {
            OrderItem::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'product_id' => Product::factory()->create([
                    'seller_id' => $seller->id,
                    'name' => $name,
                ])->id,
                'product_name' => $name,
                'quantity' => $quantity,
                'price_minor' => $priceMinor,
            ]);
        }

        return $order;
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson(route('api.orders.index'))->assertUnauthorized();
    }

    public function test_it_lists_only_the_token_holders_own_parcels(): void
    {
        $me = Buyer::factory()->create();
        $someoneElse = Buyer::factory()->create();

        $this->orderFor($me, 'Loom & Larder');
        $this->orderFor($someoneElse, 'Tahanan Ceramics');

        Sanctum::actingAs($me);

        $this->getJson(route('api.orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop', 'Loom & Larder');
    }

    public function test_the_shape_the_apps_order_model_reads(): void
    {
        $me = Buyer::factory()->create();

        $this->orderFor($me, 'Loom & Larder', [
            ['Handwoven Rattan Tote', 1, 125075],
            ['Kamagong Stool', 2, 380000],
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        $response->assertJsonStructure([
            'data' => [
                [
                    'id', 'reference', 'status', 'shop', 'subtotal_minor',
                    'items' => [['name', 'quantity', 'price_minor', 'product_id']],
                    'product_ids',
                ],
            ],
        ]);

        // A string id, like every other id the app reads: its order list, its
        // tracker and its review joins key on strings that came from the mock.
        $this->assertIsString($response->json('data.0.id'));
        $this->assertStringStartsWith('ORD-', $response->json('data.0.reference'));

        // The server's own status vocabulary, not the app's four display stages —
        // thirteen is not four, and the app is the side that chose to simplify.
        $this->assertSame('PLACED', $response->json('data.0.status'));

        // Centavos, added up the way the columns hold them.
        $this->assertSame(885075, $response->json('data.0.subtotal_minor'));
        $this->assertCount(2, $response->json('data.0.items'));
        $this->assertSame('Handwoven Rattan Tote', $response->json('data.0.items.0.name'));
        $this->assertSame(1, $response->json('data.0.items.0.quantity'));
        $this->assertSame(125075, $response->json('data.0.items.0.price_minor'));
        $this->assertSame(2, $response->json('data.0.items.1.quantity'));
        $this->assertCount(2, $response->json('data.0.product_ids'));
    }

    public function test_a_removed_product_keeps_its_line_and_drops_its_id(): void
    {
        $me = Buyer::factory()->create();
        $this->orderFor($me, 'Loom & Larder', [['Capiz Shell Wind Chime', 1, 68000]]);

        // `order_items.product_id` is nullOnDelete on purpose: the line is the audit
        // record of what was bought, and the product may not exist any more.
        OrderItem::query()->sole()->product->forceDelete();

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        $this->assertSame('Capiz Shell Wind Chime', $response->json('data.0.items.0.name'));
        $this->assertNull($response->json('data.0.items.0.product_id'));
        // And no null in the id list: the app's `productIds` means "the catalogue
        // items this order contains", so a removed product is simply absent.
        $this->assertSame([], $response->json('data.0.product_ids'));
    }

    public function test_a_two_shop_checkout_is_two_parcels_kept_together_and_newest_first(): void
    {
        $me = Buyer::factory()->create();

        $this->orderFor($me, 'Hiraya Weaves');
        $this->travel(1)->minutes();
        $newer = $this->orderFor($me, 'Kape ni Lolo');

        // The second parcel belongs to the newer checkout, so it sits beside its
        // sibling: ordering is by the parent order's timestamp, which keeps one
        // checkout's parcels together rather than interleaving them by their ids.
        SellerOrder::query()->create([
            'order_id' => $newer->id,
            'seller_id' => Seller::factory()->create(['business_name' => 'Alon Botanicals'])->id,
            'subtotal_minor' => 100,
        ]);

        Sanctum::actingAs($me);

        $shops = $this->getJson(route('api.orders.index'))->assertOk()->json('data.*.shop');

        $this->assertSame(['Kape ni Lolo', 'Alon Botanicals', 'Hiraya Weaves'], $shops);
    }
}
