<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/orders` — the phone's basket, placed through the web's own rules.
 *
 * This is the first *write* on the API, and the first time the app's door reaches
 * money code, so the assertions are about the two things that could quietly be
 * wrong: who owns the order, and where the amounts came from.
 *
 * The amount assertions are the point of the endpoint. The app's cart lives on
 * the device, which makes the request a *proposal* — product ids and quantities —
 * and everything priced has to be re-derived on the server. So one test
 * deliberately posts a bargain (`price_minor: 1`, plus a line total and an order
 * subtotal for good measure) and asserts the order is still charged the
 * catalogue's price. A service that trusted any of them would be a way to buy
 * anything for a peso.
 *
 * The rules themselves — the lock, the centavos, the discount split, the rollback
 * — are `OrderPlacerTest`'s subject. What is checked here is that this route hands
 * the service its lines and its voucher correctly, answers in the shape the app
 * already parses, and gives a refusal the app can act on.
 */
class PlaceOrderApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(
        string $shopName = 'Loom & Larder',
        float $price = 1250.75,
        int $stock = 10,
        string $name = 'Handwoven Rattan Tote',
    ): Product {
        $seller = Seller::factory()->create([
            'business_name' => $shopName,
            'status' => 'approved',
        ]);

        return Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertUnauthorized();
    }

    public function test_it_places_the_order_and_answers_with_the_same_parcels_the_history_returns(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product();

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        // The shape `GET /orders` sends, so the app parses one payload for placing
        // and for listing.
        $response->assertJsonStructure([
            'data' => [
                ['id', 'reference', 'status', 'shop', 'subtotal_minor', 'items', 'product_ids'],
            ],
            'meta' => ['order_id', 'reference', 'subtotal_minor', 'discount_minor', 'total_minor'],
        ]);

        $this->assertSame('Loom & Larder', $response->json('data.0.shop'));
        $this->assertSame('PLACED', $response->json('data.0.status'));
        $this->assertSame(250150, $response->json('data.0.subtotal_minor'));
        $this->assertSame([(string) $product->id], $response->json('data.0.product_ids'));

        // Order-level money beside the parcels: a discount is split *across*
        // parcels, so only the order can report the total honestly.
        $this->assertSame(250150, $response->json('meta.subtotal_minor'));
        $this->assertSame(0, $response->json('meta.discount_minor'));
        $this->assertSame(250150, $response->json('meta.total_minor'));
        $this->assertSame($response->json('meta.reference'), $response->json('data.0.reference'));

        // And the rows exist, owned by the token holder — never by a body field.
        $order = Order::query()->sole();
        $this->assertSame($me->id, $order->buyer_id);
        $this->assertSame(250150, $order->total_minor);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_request_cannot_set_a_price(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);

        Sanctum::actingAs($me);

        $this->postJson(route('api.orders.store'), [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price_minor' => 1,
                'unit_price' => 1,
                'line_total_minor' => 1,
            ]],
            'subtotal_minor' => 1,
            'total_minor' => 1,
        ])->assertCreated();

        // The catalogue's price, not the one the body offered.
        $this->assertSame(125075, OrderItem::query()->sole()->price_minor);
        $this->assertSame(125075, Order::query()->sole()->total_minor);
    }

    public function test_a_two_shop_basket_answers_with_one_parcel_per_shop_and_one_reference(): void
    {
        $me = Buyer::factory()->create();
        $tote = $this->product('Loom & Larder', price: 1000.00);
        $bowls = $this->product('Tahanan Ceramics', price: 500.00, name: 'Stoneware Rice Bowl Set');

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [
                ['product_id' => $tote->id, 'quantity' => 1],
                ['product_id' => $bowls->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $response->assertJsonCount(2, 'data');

        $this->assertSame(
            ['Loom & Larder', 'Tahanan Ceramics'],
            $response->json('data.*.shop'),
        );

        // One checkout, one order number: both parcels report it, and it is the
        // same string in `meta` — that is how the app groups the rows it draws.
        $this->assertSame(
            [$response->json('meta.reference')],
            array_values(array_unique($response->json('data.*.reference'))),
        );
        $this->assertSame(150000, $response->json('meta.subtotal_minor'));

        // Two seller orders under one order row: the split is the checkout's, not
        // the request's.
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_a_voucher_code_is_applied_and_its_money_lands_in_meta(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);
        Voucher::factory()->fixed(20000)->create(['code' => 'ZEFANYANEW']);

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            // Lower-case on purpose: `resolveCode` upper-cases what the shopper
            // typed, and a phone keyboard is no reason to refuse a valid code.
            'voucher_code' => 'zefanyanew',
        ])->assertCreated();

        $this->assertSame(20000, $response->json('meta.discount_minor'));
        $this->assertSame(230150, $response->json('meta.total_minor'));
        $this->assertSame(20000, Order::query()->sole()->discount_minor);
    }

    public function test_a_voucher_that_does_not_apply_is_refused_rather_than_dropped(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);

        Sanctum::actingAs($me);

        // Silently dropping it would place a full-price order while the phone's
        // basket still showed a discount. Refusing keeps the two in step.
        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'voucher_code' => 'NOT-A-REAL-CODE',
        ])->assertStatus(422);

        $this->assertSame('NOT-A-REAL-CODE', $response->json('voucher_rejected'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_basket_that_is_no_longer_placeable_answers_422_and_writes_nothing(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(stock: 1);

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422);

        // A sentence the app can show, rather than an opaque 500: the phone's
        // basket is stale, and refreshing it is the person's next act.
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));

        // Rolled back whole: no order, no payment, and the stock is untouched.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_validation_refuses_an_empty_or_malformed_basket(): void
    {
        Sanctum::actingAs(Buyer::factory()->create());

        $this->postJson(route('api.orders.store'), ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->postJson(route('api.orders.store'), ['items' => [['product_id' => 1, 'quantity' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->postJson(route('api.orders.store'), ['items' => [['quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }
}
