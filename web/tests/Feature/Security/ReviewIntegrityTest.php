<?php

namespace Tests\Feature\Security;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H4: a review is a verified statement about a delivered purchase.
 *
 * The server, not the client, decides eligibility (`DELIVERED`), the reviewed
 * line (`order_item_id`), the verified badge, the reviewer name, and the photo
 * set. Every test writes a review through the API rather than the model, so
 * the assertions cover the path the app uses.
 */
class ReviewIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ---- eligibility -------------------------------------------------------

    public function test_an_undelivered_order_cannot_be_reviewed(): void
    {
        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase(delivered: false);

        Sanctum::actingAs($buyer);

        $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
            'stars' => 5,
            'comment' => 'Paid before it arrived.',
        ])->assertStatus(422);

        $this->assertSame(0, Review::query()->count());
    }

    public function test_another_buyers_undelivered_order_is_forbidden_not_validated(): void
    {
        ['sellerOrder' => $theirOrder, 'product' => $product] = $this->purchase(delivered: false);
        $interloper = Buyer::factory()->create();

        Sanctum::actingAs($interloper);

        $this->patchJson(route('api.reviews.upsert', [$theirOrder, $product]), [
            'stars' => 1,
        ])->assertForbidden();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_delivered_order_is_reviewable(): void
    {
        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
            'stars' => 5,
        ])->assertCreated();
    }

    // ---- server-computed fields -------------------------------------------

    public function test_the_server_computes_verified_purchase_and_the_order_item(): void
    {
        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product, 'orderItem' => $item] = $this->purchase();

        Sanctum::actingAs($buyer);

        $response = $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
            'stars' => 4,
            // A client cannot grant itself the badge, or point the review at
            // somebody else's purchase line.
            'verified_purchase' => false,
            'order_item_id' => 999999,
            'buyer_id' => 999999,
        ])->assertCreated();

        $response->assertJsonPath('data.verified_purchase', true);

        $review = Review::query()->sole();
        $this->assertTrue($review->verified_purchase);
        $this->assertSame($item->id, $review->order_item_id);
        $this->assertSame($buyer->id, $review->buyer_id);
    }

    public function test_one_review_per_order_item_is_a_database_rule(): void
    {
        $this->assertTrue(
            Schema::hasIndex('reviews', 'reviews_order_item_unique', 'unique'),
            'reviews.order_item_id must be unique.',
        );

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product, 'orderItem' => $item] = $this->purchase();

        Sanctum::actingAs($buyer);

        $this->patchJson(route('api.reviews.upsert', [$order, $product]), ['stars' => 2])
            ->assertCreated();
        $this->patchJson(route('api.reviews.upsert', [$order, $product]), ['stars' => 5])
            ->assertOk();

        $this->assertSame(1, Review::query()->count());
        $this->assertSame($item->id, Review::query()->sole()->order_item_id);
    }

    public function test_reviewer_identity_is_masked_server_side(): void
    {
        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        $buyer->forceFill([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.delacruz@example.com',
        ])->save();

        Sanctum::actingAs($buyer);

        $response = $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
            'stars' => 5,
            'comment' => 'Excellent.',
        ])->assertCreated();

        $name = $response->json('data.reviewer_name');

        $this->assertSame('J*** D***', $name);
        $this->assertStringNotContainsString('Juan', $name);
        $this->assertStringNotContainsString('Dela Cruz', $name);
        $this->assertStringNotContainsString('juan.delacruz@example.com', json_encode($response->json('data')));
    }

    // ---- photos ------------------------------------------------------------

    public function test_four_jpg_png_webp_photos_are_stored(): void
    {
        Storage::fake('public');

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $response = $this->post(route('api.reviews.upsert', [$order, $product]), [
            // PHP only parses multipart bodies on POST; `_method` is Laravel's
            // standard way to reach a PATCH route with files in production.
            '_method' => 'PATCH',
            'stars' => 5,
            'photos' => [
                UploadedFile::fake()->create('one.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('two.png', 100, 'image/png'),
                UploadedFile::fake()->create('three.webp', 100, 'image/webp'),
                UploadedFile::fake()->create('four.jpg', 100, 'image/jpeg'),
            ],
        ])->assertCreated();

        $response->assertJsonCount(4, 'data.photo_urls');

        $stored = Storage::disk('public')->files('reviews');
        $this->assertCount(4, $stored);

        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression('/\.(jpg|png|webp)$/', $path);
        }

        $recorded = Review::query()->sole()->photo_paths;
        sort($stored);
        sort($recorded);
        $this->assertSame($stored, $recorded);
    }

    public function test_a_fifth_photo_is_refused(): void
    {
        Storage::fake('public');

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $photos = [];

        for ($i = 0; $i < 5; $i++) {
            $photos[] = UploadedFile::fake()->create("photo-{$i}.jpg", 100, 'image/jpeg');
        }

        $this->post(route('api.reviews.upsert', [$order, $product]), [
            '_method' => 'PATCH',
            'stars' => 5,
            'photos' => $photos,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        $this->assertSame(0, Review::query()->count());
        $this->assertSame([], Storage::disk('public')->files('reviews'));
    }

    public function test_a_non_image_photo_is_refused(): void
    {
        Storage::fake('public');

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $this->post(route('api.reviews.upsert', [$order, $product]), [
            '_method' => 'PATCH',
            'stars' => 5,
            'photos' => [
                UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('photos.0');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_photo_over_five_megabytes_is_refused(): void
    {
        Storage::fake('public');

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $this->post(route('api.reviews.upsert', [$order, $product]), [
            '_method' => 'PATCH',
            'stars' => 5,
            'photos' => [
                UploadedFile::fake()->create('huge.jpg', 5121, 'image/jpeg'),
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('photos.0');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_editing_without_photos_keeps_the_stored_set(): void
    {
        Storage::fake('public');

        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        $this->post(route('api.reviews.upsert', [$order, $product]), [
            '_method' => 'PATCH',
            'stars' => 3,
            'photos' => [
                UploadedFile::fake()->create('keep.jpg', 100, 'image/jpeg'),
            ],
        ])->assertCreated();

        $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
            'stars' => 4,
            'comment' => 'Changed my mind about the stars.',
        ])->assertOk()
            ->assertJsonCount(1, 'data.photo_urls');

        $this->assertCount(1, Storage::disk('public')->files('reviews'));
    }

    // ---- rating bounds -----------------------------------------------------

    public function test_only_one_to_five_stars_are_accepted(): void
    {
        ['buyer' => $buyer, 'sellerOrder' => $order, 'product' => $product] = $this->purchase();

        Sanctum::actingAs($buyer);

        foreach ([0, 6] as $stars) {
            $this->patchJson(route('api.reviews.upsert', [$order, $product]), [
                'stars' => $stars,
            ])->assertStatus(422)->assertJsonValidationErrors('stars');
        }

        $this->assertSame(0, Review::query()->count());
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @return array{buyer: Buyer, sellerOrder: SellerOrder, product: Product, orderItem: OrderItem}
     */
    private function purchase(bool $delivered = true): array
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

        if ($delivered) {
            $sellerOrder->forceFill(['status' => OrderStatus::Delivered])->save();
        }

        $orderItem = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        return [
            'buyer' => $buyer,
            'sellerOrder' => $sellerOrder,
            'product' => $product,
            'orderItem' => $orderItem,
        ];
    }
}
