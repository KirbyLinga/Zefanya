<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Rule08ProductReviewsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function buyer(): Buyer
    {
        return Buyer::factory()->create();
    }

    private function seller(): Seller
    {
        return Seller::factory()->create();
    }

    private function product(Seller $seller): Product
    {
        return Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 10,
        ]);
    }

    private function item(
        Buyer $buyer,
        Seller $seller,
        Product $product,
        OrderStatus $status = OrderStatus::Completed,
        ?Order $order = null,
    ): OrderItem {
        $order ??= Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
        ]);
        $sellerOrder->forceFill(['status' => $status->value])->save();

        return OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 10000,
        ]);
    }

    private function review(OrderItem $item, int $rating = 5, ?string $body = 'Great item'): Review
    {
        return Review::query()->create([
            'buyer_id' => $item->sellerOrder->order->buyer_id,
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'rating' => $rating,
            'body' => $body,
        ]);
    }

    public function test_buy_rate_002_only_a_completed_purchase_owned_by_the_buyer_can_be_reviewed(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);

        $delivered = $this->item($buyer, $seller, $product, OrderStatus::Delivered);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $delivered->id,
            'rating' => 5,
            'body' => 'Delivered but not completed.',
        ])->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);

        $someoneElses = $this->item($otherBuyer, $seller, $product);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $someoneElses->id,
            'rating' => 5,
        ])->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);

        $own = $this->item($buyer, $seller, $product);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $own->id,
            'rating' => 5,
            'body' => 'Completed purchase review.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'order_item_id' => $own->id,
            'rating' => 5,
        ]);

        // Owning a completed purchase still cannot review another buyer's item.
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $someoneElses->id,
            'rating' => 5,
        ])->assertSessionHasErrors('order_item_id');
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_buy_rate_003_allows_one_review_per_purchased_item_and_multiple_items_per_product(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $first = $this->item($buyer, $seller, $product);
        $second = $this->item($buyer, $seller, $product);

        $this->actingAs($buyer, 'buyer')
            ->post(route('buyer.products.reviews.store', $product), ['rating' => 4, 'body' => 'First item'])
            ->assertSessionHasNoErrors();
        $this->actingAs($buyer, 'buyer')
            ->post(route('buyer.products.reviews.store', $product), ['rating' => 5, 'body' => 'Second item'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseHas('reviews', ['order_item_id' => $first->id, 'body' => 'First item']);
        $this->assertDatabaseHas('reviews', ['order_item_id' => $second->id, 'body' => 'Second item']);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $first->id,
            'rating' => 1,
            'body' => 'Duplicate attempt',
        ])->assertSessionHasErrors('order_item_id');

        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseMissing('reviews', ['body' => 'Duplicate attempt']);
    }

    public function test_buy_rate_003_database_unique_index_blocks_a_duplicate_order_item_review(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $item = $this->item($buyer, $seller, $product);

        $this->review($item);

        $this->expectException(QueryException::class);
        $this->review($item);
    }

    public function test_buy_rate_004_enforces_the_500_character_review_body_boundary(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $first = $this->item($buyer, $seller, $product);
        $second = $this->item($buyer, $seller, $product);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $first->id,
            'rating' => 5,
            'body' => str_repeat('a', 500),
        ])->assertSessionHasNoErrors();

        $this->assertSame(500, mb_strlen(Review::query()->sole()->body));

        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $second->id,
            'rating' => 5,
            'body' => str_repeat('b', 501),
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_buy_rate_004_accepts_five_valid_photos_and_rejects_six_or_invalid_files(): void
    {
        Storage::fake('public');

        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);

        $first = $this->item($buyer, $seller, $product);
        $photos = collect(range(1, 5))->map(fn (int $number) => UploadedFile::fake()->image("review-{$number}.jpg", 20, 20))->all();
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $first->id,
            'rating' => 5,
            'photos' => $photos,
        ])->assertSessionHasNoErrors();

        $review = Review::query()->sole();
        $this->assertCount(5, $review->photos);
        foreach ($review->photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }

        $second = $this->item($buyer, $seller, $product);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $second->id,
            'rating' => 5,
            'photos' => collect(range(1, 6))->map(fn (int $number) => UploadedFile::fake()->image("too-many-{$number}.jpg", 20, 20))->all(),
        ])->assertSessionHasErrors('photos');
        $this->assertDatabaseCount('review_photos', 5);

        $third = $this->item($buyer, $seller, $product);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $third->id,
            'rating' => 5,
            'photos' => [UploadedFile::fake()->create('not-a-photo.jpg', 10, 'text/plain')],
        ])->assertSessionHasErrors('photos.0');
        $this->assertDatabaseCount('review_photos', 5);
    }

    public function test_buy_rate_005_buyer_can_edit_exactly_once_within_the_thirty_day_window(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $item = $this->item($buyer, $seller, $product);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'order_item_id' => $item->id,
            'rating' => 3,
            'body' => 'Original review',
        ])->assertSessionHasNoErrors();

        $review = Review::query()->sole();
        Carbon::setTestNow('2026-09-20 12:00:00');

        $this->actingAs($buyer, 'buyer')->patch(route('buyer.reviews.update', $review), [
            'rating' => 4,
            'body' => 'Edited once',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $review->refresh();
        $this->assertSame(4, $review->rating);
        $this->assertSame('Edited once', $review->body);
        $this->assertNotNull($review->buyer_edited_at);

        $this->actingAs($buyer, 'buyer')->patch(route('buyer.reviews.update', $review), [
            'rating' => 5,
            'body' => 'Second edit attempt',
        ])->assertSessionHasErrors('review');

        $review->refresh();
        $this->assertSame(4, $review->rating);
        $this->assertSame('Edited once', $review->body);
    }

    public function test_buy_rate_005_thirty_day_boundary_is_inclusive(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $review = $this->review($this->item($buyer, $seller, $product));
        $review->forceFill(['created_at' => Carbon::now(), 'updated_at' => Carbon::now()])->save();

        Carbon::setTestNow('2026-10-01 12:00:00');

        $this->actingAs($buyer, 'buyer')->patch(route('buyer.reviews.update', $review), [
            'rating' => 4,
            'body' => 'Boundary edit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Boundary edit', $review->refresh()->body);
    }

    public function test_buy_rate_005_buyer_cannot_edit_after_thirty_days(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');

        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $review = $this->review($this->item($buyer, $seller, $product));
        $review->forceFill(['created_at' => Carbon::now(), 'updated_at' => Carbon::now()])->save();

        Carbon::setTestNow('2026-09-01 12:00:01');

        $this->actingAs($buyer, 'buyer')->patch(route('buyer.reviews.update', $review), [
            'rating' => 1,
            'body' => 'Too late',
        ])->assertSessionHasErrors('review');

        $review->refresh();
        $this->assertSame(5, $review->rating);
        $this->assertSame('Great item', $review->body);
        $this->assertNull($review->buyer_edited_at);
    }

    public function test_buy_rate_005_another_buyer_cannot_edit_someone_elses_review(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $review = $this->review($this->item($buyer, $seller, $product));

        $this->actingAs($otherBuyer, 'buyer')->patch(route('buyer.reviews.update', $review), [
            'rating' => 1,
            'body' => 'Hijacked',
        ])->assertForbidden();

        $this->assertSame('Great item', $review->refresh()->body);
    }

    public function test_sel_rev_002_and_003_seller_can_reply_once_and_edit_that_reply(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $review = $this->review($this->item($buyer, $seller, $product));

        $this->actingAs($seller, 'seller')->post(route('seller.feedback.reply', $review), [
            'reply' => 'Thank you for your feedback.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $review->refresh();
        $this->assertSame('Thank you for your feedback.', $review->seller_reply);
        $this->assertNotNull($review->seller_replied_at);

        $this->actingAs($seller, 'seller')->post(route('seller.feedback.reply', $review), [
            'reply' => 'A second reply must not replace the first.',
        ])->assertSessionHasErrors('reply');
        $this->assertSame('Thank you for your feedback.', $review->refresh()->seller_reply);

        $this->actingAs($seller, 'seller')->patch(route('seller.feedback.reply.update', $review), [
            'reply' => 'Thank you. We have followed up with you.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $review->refresh();
        $this->assertSame('Thank you. We have followed up with you.', $review->seller_reply);
        $this->assertNotNull($review->seller_reply_edited_at);
    }

    public function test_sel_rev_004_seller_cannot_delete_or_hide_a_buyer_review_and_other_sellers_cannot_reply(): void
    {
        $this->withoutVite();
        Storage::fake('public');

        $buyer = $this->buyer();
        $seller = $this->seller();
        $otherSeller = $this->seller();
        $product = $this->product($seller);
        $review = $this->review($this->item($buyer, $seller, $product));

        $this->actingAs($otherSeller, 'seller')->post(route('seller.feedback.reply', $review), [
            'reply' => 'Unauthorized reply',
        ])->assertForbidden();
        $this->assertNull($review->refresh()->seller_reply);

        $this->actingAs($seller, 'seller')->post(route('seller.feedback.reply', $review), [
            'reply' => 'Visible seller reply',
        ])->assertSessionHasNoErrors();

        $this->actingAs($seller, 'seller')
            ->delete("/seller/reviews/{$review->id}/reply")
            ->assertStatus(405);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'body' => 'Great item']);

        $this->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('Great item')
            ->assertSee('Visible seller reply');
    }

    public function test_sel_rev_004_seller_routes_offer_no_way_to_delete_or_hide_a_review(): void
    {
        $reviewRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'seller.'))
            ->filter(fn ($route) => str_contains($route->uri(), 'review') || str_contains($route->uri(), 'feedback'));

        $this->assertNotEmpty($reviewRoutes);

        foreach ($reviewRoutes as $route) {
            $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            $this->assertDoesNotMatchRegularExpression('/hide|delete|remove/i', $route->uri());
        }
    }

    public function test_sel_rev_reply_must_be_1_to_1000_characters_on_post_and_edit(): void
    {
        $seller = $this->seller();
        $review = $this->review($this->item($this->buyer(), $seller, $this->product($seller)));
        $this->actingAs($seller, 'seller');

        $this->post(route('seller.feedback.reply', $review), ['reply' => str_repeat('a', 1001)])->assertSessionHasErrors('reply');
        $this->post(route('seller.feedback.reply', $review), ['reply' => '   '])->assertSessionHasErrors('reply');
        $this->assertNull($review->refresh()->seller_reply);

        $this->post(route('seller.feedback.reply', $review), ['reply' => str_repeat('a', 1000)])->assertSessionHasNoErrors();
        $this->patch(route('seller.feedback.reply.update', $review), ['reply' => str_repeat('b', 1001)])->assertSessionHasErrors('reply');
        $this->assertSame(str_repeat('a', 1000), $review->refresh()->seller_reply);

        $this->patch(route('seller.feedback.reply.update', $review), ['reply' => 'b'])->assertSessionHasNoErrors();
        $this->assertSame('b', $review->refresh()->seller_reply);
    }

    public function test_sel_rev_other_sellers_cannot_edit_a_reply_and_nothing_can_be_edited_before_replying(): void
    {
        $seller = $this->seller();
        $review = $this->review($this->item($this->buyer(), $seller, $this->product($seller)));

        $this->actingAs($seller, 'seller')
            ->patch(route('seller.feedback.reply.update', $review), ['reply' => 'No reply exists yet'])
            ->assertSessionHasErrors('reply');
        $this->assertNull($review->refresh()->seller_reply);

        $this->post(route('seller.feedback.reply', $review), ['reply' => 'My reply'])->assertSessionHasNoErrors();

        $this->actingAs($this->seller(), 'seller')
            ->patch(route('seller.feedback.reply.update', $review), ['reply' => 'Hijacked'])
            ->assertForbidden();
        $this->assertSame('My reply', $review->refresh()->seller_reply);
    }

    public function test_sel_rev_reply_error_and_draft_stay_on_the_review_that_failed(): void
    {
        $this->withoutVite();

        $seller = $this->seller();
        $product = $this->product($seller);
        $failed = $this->review($this->item($this->buyer(), $seller, $product));
        $other = $this->review($this->item($this->buyer(), $seller, $product));
        $other->forceFill(['seller_reply' => 'Saved reply', 'seller_replied_at' => now()])->save();

        $draft = str_repeat('x', 1001);

        $html = $this->actingAs($seller, 'seller')
            ->from(route('seller.feedback.index'))
            ->followingRedirects()
            ->post(route('seller.feedback.reply', $failed), ['reply' => $draft, 'review_id' => $failed->id])
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, $draft));
        $this->assertSame(1, substr_count($html, 'role="alert"'));
        $this->assertStringContainsString('Saved reply', $html);
    }

    public function test_seller_feedback_page_only_lists_the_sellers_own_reviews(): void
    {
        $this->withoutVite();

        $buyer = $this->buyer();
        $seller = $this->seller();
        $otherSeller = $this->seller();
        $ownProduct = $this->product($seller);
        $otherProduct = $this->product($otherSeller);
        $this->review($this->item($buyer, $seller, $ownProduct), 4, 'Review for the owning seller');
        $this->review($this->item($buyer, $otherSeller, $otherProduct), 2, 'Review for another seller');

        $this->actingAs($seller, 'seller')
            ->get(route('seller.feedback.index'))
            ->assertOk()
            ->assertSee('Review for the owning seller')
            ->assertDontSee('Review for another seller')
            ->assertSee('Post reply');
    }

    public function test_product_page_exposes_review_forms_only_for_completed_reviewable_purchases(): void
    {
        $this->withoutVite();

        $buyer = $this->buyer();
        $seller = $this->seller();
        $product = $this->product($seller);
        $this->item($buyer, $seller, $product);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('Submit review')
            ->assertSee('name="order_item_id"', false);

        $this->actingAs($buyer, 'buyer')->post(route('buyer.products.reviews.store', $product), [
            'rating' => 5,
            'body' => 'Reviewed from the product page',
        ])->assertSessionHasNoErrors();

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('Reviewed from the product page')
            ->assertSee('Update review');
    }

    public function test_buy_rate_006_masks_buyer_names_and_shows_average_and_count_on_the_search_card(): void
    {
        $this->withoutVite();

        $buyer = Buyer::factory()->create([
            'first_name' => 'Juan',
            'middle_initial' => null,
            'last_name' => 'Cruz',
        ]);
        $other = Buyer::factory()->create([
            'first_name' => 'Maria',
            'middle_initial' => null,
            'last_name' => 'Santos',
        ]);
        $seller = $this->seller();
        $product = $this->product($seller);
        $product->forceFill(['name' => 'Linen Tote Bag'])->save();

        $this->review($this->item($buyer, $seller, $product), 4, 'Soft fabric');
        $this->review($this->item($other, $seller, $product), 5, 'Second review');

        $this->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('J**n C**z')
            ->assertSee('M***a S****s')
            ->assertDontSee('Juan Cruz')
            ->assertDontSee('Maria Santos');

        $this->get(route('buyer.stores.show', ['seller' => $seller, 'tab' => 'reviews']))
            ->assertOk()
            ->assertSee('J**n C**z')
            ->assertDontSee('Juan Cruz');

        $this->get(route('buyer.search.index', ['q' => 'Linen Tote']))
            ->assertOk()
            ->assertSee('4.5')
            ->assertSee('(2)')
            ->assertSee('Average rating 4.5 from 2 reviews', false);
    }
}
