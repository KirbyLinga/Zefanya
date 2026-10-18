<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reviews — `GET /api/reviews/mine` and `PUT /api/orders/{sellerOrder}/items/{product}/review`.
 *
 * The two things worth proving:
 *
 *  1. **Scoping.** A review belongs to the buyer who wrote it. No token, no reviews.
 *     One buyer's token cannot read or write another buyer's reviews. A product that
 *     was not in an order cannot be reviewed against it.
 *
 *  2. **The upsert contract.** The first submission is a create (`201`). A second
 *     submission for the same (order, product) is an update (`200`), not a duplicate.
 *     The shape `ProductReview.fromJson` on the Dart side reads.
 */
class ReviewApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A buyer, one of their seller orders, and one product that was in it.
     *
     * @return array{buyer: Buyer, sellerOrder: SellerOrder, product: Product}
     */
    private function purchase(): array
    {
        $buyer = Buyer::factory()->create();

        $seller = Seller::factory()->create([
            'business_name' => 'Tahanan Ceramics',
            'status' => 'approved',
        ]);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Stoneware Rice Bowl Set',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['full' => '12-A Sanciangko Street, Lahug, Cebu City'],
            'subtotal_minor' => 50000,
            'discount_minor' => 0,
            'total_minor' => 50000,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 50000,
        ]);

        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        return compact('buyer', 'sellerOrder', 'product');
    }

    // ── GET /api/reviews/mine ────────────────────────────────────────────────

    public function test_mine_requires_a_token(): void
    {
        $this->getJson(route('api.reviews.mine'))->assertUnauthorized();
    }

    public function test_mine_returns_empty_when_no_reviews_written(): void
    {
        Sanctum::actingAs(Buyer::factory()->create());

        $this->getJson(route('api.reviews.mine'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mine_returns_only_this_buyers_reviews(): void
    {
        ['buyer' => $me, 'sellerOrder' => $myOrder, 'product' => $product] = $this->purchase();
        ['buyer' => $other, 'sellerOrder' => $theirOrder] = $this->purchase();

        // My review
        Review::query()->create([
            'buyer_id' => $me->id,
            'seller_order_id' => $myOrder->id,
            'product_id' => $product->id,
            'stars' => 4,
            'comment' => 'Lovely bowls.',
        ]);

        // Stranger's review for the same product in their own order
        Review::query()->create([
            'buyer_id' => $other->id,
            'seller_order_id' => $theirOrder->id,
            'product_id' => $product->id,
            'stars' => 3,
            'comment' => 'Decent.',
        ]);

        Sanctum::actingAs($me);

        $this->getJson(route('api.reviews.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stars', 4)
            ->assertJsonPath('data.0.comment', 'Lovely bowls.');
    }

    public function test_mine_returns_the_shape_the_app_reads(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Review::query()->create([
            'buyer_id' => $me->id,
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'stars' => 5,
            'comment' => 'Perfect.',
            'photo_path' => null,
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.reviews.mine'))->assertOk();

        $response->assertJsonStructure([
            'data' => [
                ['id', 'order_id', 'product_id', 'stars', 'comment', 'photo_url', 'created_at'],
            ],
        ]);

        // order_id is the seller order's id (what the app calls its "order id").
        $this->assertSame((string) $sellerOrder->id, $response->json('data.0.order_id'));
        $this->assertSame((string) $product->id, $response->json('data.0.product_id'));
        $this->assertSame(5, $response->json('data.0.stars'));
        $this->assertNull($response->json('data.0.photo_url'));
        // A string id, like every other id the app reads.
        $this->assertIsString($response->json('data.0.id'));
        // ISO 8601, so the Dart side can parse it with DateTime.parse.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $response->json('data.0.created_at'));
    }

    public function test_mine_is_newest_first(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Review::query()->create([
            'buyer_id' => $me->id,
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'stars' => 2,
            'comment' => 'First.',
        ]);

        $this->travel(1)->minutes();

        // A second product in the same order, to have a second review.
        $seller = $sellerOrder->seller;
        $second = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Celadon Cup',
            'status' => 'active',
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $second->id,
            'product_name' => $second->name,
            'quantity' => 1,
            'price_minor' => 25000,
        ]);
        Review::query()->create([
            'buyer_id' => $me->id,
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $second->id,
            'stars' => 5,
            'comment' => 'Second, written later.',
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.reviews.mine'))->assertOk();

        $this->assertSame('Second, written later.', $response->json('data.0.comment'));
        $this->assertSame('First.', $response->json('data.1.comment'));
    }

    // ── PUT /api/orders/{sellerOrder}/items/{product}/review ─────────────────

    public function test_upsert_requires_a_token(): void
    {
        ['sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 4],
        )->assertUnauthorized();
    }

    public function test_upsert_creates_a_review_and_answers_201(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($me);

        $response = $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 5, 'comment' => 'Beautiful craftsmanship.'],
        )->assertCreated();

        $response->assertJsonStructure([
            'data' => ['id', 'order_id', 'product_id', 'stars', 'comment', 'photo_url', 'created_at'],
        ]);

        $this->assertSame((string) $sellerOrder->id, $response->json('data.order_id'));
        $this->assertSame((string) $product->id, $response->json('data.product_id'));
        $this->assertSame(5, $response->json('data.stars'));
        $this->assertSame('Beautiful craftsmanship.', $response->json('data.comment'));

        $this->assertSame(1, Review::query()->count());
    }

    public function test_upsert_replaces_an_existing_review_and_answers_200(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($me);

        // First write.
        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 2, 'comment' => 'Not sure yet.'],
        )->assertCreated();

        // Second write for the same purchase — must be an edit, not a duplicate.
        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 5, 'comment' => 'Grew on me.'],
        )->assertOk();

        // Still exactly one row.
        $this->assertSame(1, Review::query()->count());

        $review = Review::query()->sole();
        $this->assertSame(5, $review->stars);
        $this->assertSame('Grew on me.', $review->comment);
    }

    public function test_upsert_refuses_a_purchase_that_belongs_to_another_buyer(): void
    {
        ['sellerOrder' => $theirOrder, 'product' => $product] = $this->purchase();
        $interloper = Buyer::factory()->create();

        Sanctum::actingAs($interloper);

        // Should be 403 — the order is not theirs.
        $this->patchJson(
            route('api.reviews.upsert', [$theirOrder, $product]),
            ['stars' => 1],
        )->assertForbidden();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_upsert_refuses_a_product_not_in_the_order(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder] = $this->purchase();

        // A product from a completely different seller — not in this order at all.
        $unrelatedProduct = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'name' => 'Interloper Product',
            'status' => 'active',
        ]);

        Sanctum::actingAs($me);

        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $unrelatedProduct]),
            ['stars' => 5],
        )->assertStatus(422);

        $this->assertSame(0, Review::query()->count());
    }

    public function test_upsert_validates_stars_range(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($me);

        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 0],
        )->assertStatus(422)
         ->assertJsonValidationErrors('stars');

        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 6],
        )->assertStatus(422)
         ->assertJsonValidationErrors('stars');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_upsert_accepts_a_review_with_no_comment(): void
    {
        ['buyer' => $me, 'sellerOrder' => $sellerOrder, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($me);

        $this->patchJson(
            route('api.reviews.upsert', [$sellerOrder, $product]),
            ['stars' => 3],
        )->assertCreated();

        $this->assertSame('', Review::query()->sole()->comment);
    }
}
