<?php

namespace Tests\Feature\Buyer;

use App\Enums\PromotionKind;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPromotion;
use App\Models\Shared\Order;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Audit 1.17 Phase 1: the checkout review guard must be enforced by the
 * SERVER, not by the fact that a browser usually visits the review page first.
 *
 * Every case here posts to the real route. "Reviewed" state is produced by
 * actually rendering the checkout page, so the values under test are the ones
 * the application itself writes (never hand-built fingerprints).
 */
class CheckoutReviewGuardTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private Product $product;

    private Product $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->product = Product::factory()->create([
            'seller_id' => $this->seller->id, 'price' => 100.00, 'stock_quantity' => 5, 'status' => 'active',
        ]);
        $this->second = Product::factory()->create([
            'seller_id' => $this->seller->id, 'price' => 100.00, 'stock_quantity' => 5, 'status' => 'active',
        ]);

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
    }

    /** @return array<int, int> cart line ids in the review session */
    private function parkCart(int $quantity = 1): array
    {
        $ids = [];
        foreach ([$this->product, $this->second] as $product) {
            $ids[] = CartItem::factory()->create([
                'buyer_id' => $this->buyer->id,
                'product_id' => $product->id,
                'variant_id' => $product->variants()->sole()->id,
                'quantity' => $quantity,
            ])->id;
        }

        return $ids;
    }

    /** Actually render the review page: the app writes the token + fingerprints. */
    private function review(array $ids): void
    {
        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index', ['items' => $ids]))
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(?string $token = null, array $extra = []): array
    {
        return [
            'checkout_token' => $token ?? session(CheckoutController::TOKEN_KEY),
            ...$this->checkoutCarrierPayload([$this->seller]),
            ...$extra,
        ];
    }

    private function submitCheckout(array $payload)
    {
        return $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $payload);
    }

    // 1. No review session at all.
    public function test_direct_post_without_a_review_session_creates_no_order(): void
    {
        $token = CheckoutToken::issue($this->buyer->id);

        $this->submitCheckout($this->payload($token->token))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    // 2. Parked selection, but the reviewed token was never stored.
    public function test_post_without_a_stored_reviewed_token_is_rejected(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => null]);

        $this->submitCheckout($this->payload($token->token))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count(), 'a differing reviewed token must not place an order');
    }

    // 3. Session token A, posted token B (both valid, unused).
    public function test_a_valid_but_different_token_than_the_one_reviewed_is_rejected(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $other = CheckoutToken::issue($this->buyer->id);

        $this->submitCheckout($this->payload($other->token))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    // 4. The reviewed price lives in the session, so a forged request value is
    //    ignored. (Address and shipping fingerprints are request-supplied by
    //    design; forging those is checked separately below.)
    public function test_a_forged_price_fingerprint_is_ignored_in_favour_of_the_session(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $this->submitCheckout($this->payload(null, ['price_fingerprint' => hash('sha256', 'forged')]))
            ->assertRedirect();

        // The reviewed session still matches the live cart, so the order stands
        // and the forged value played no part.
        $this->assertSame(1, Order::query()->count());
    }

    // 4b. A forged address fingerprint is rejected, not trusted.
    public function test_a_forged_address_fingerprint_is_rejected(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $this->submitCheckout($this->payload(null, ['address_fingerprint' => hash('sha256', 'forged')]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    // 5. Price moved after review.
    public function test_a_price_change_after_review_blocks_the_order(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $this->product->variants()->sole()->update(['price' => 120.00]);
        $this->product->forceFill(['price' => 120.00])->save();

        $this->submitCheckout($this->payload())->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    // 6. Bundle created after review (cheaper than what the buyer reviewed).
    public function test_a_bundle_created_after_review_blocks_the_order(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $bundle = SellerPromotion::query()->create([
            'seller_id' => $this->seller->id, 'kind' => PromotionKind::BundlePercent,
            'name' => 'Race bundle', 'percent' => 20,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);
        $bundle->products()->attach([$this->product->id, $this->second->id]);

        $this->submitCheckout($this->payload())->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count(), 'an unreviewed new discount must not be applied silently');
    }

    // 7. Bundle deleted after review (buyer would keep a discount that no longer exists).
    public function test_a_bundle_deleted_after_review_blocks_the_order(): void
    {
        $bundle = SellerPromotion::query()->create([
            'seller_id' => $this->seller->id, 'kind' => PromotionKind::BundlePercent,
            'name' => 'Live bundle', 'percent' => 20,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);
        $bundle->products()->attach([$this->product->id, $this->second->id]);

        $ids = $this->parkCart();
        $this->review($ids);

        $bundle->products()->detach();
        $bundle->delete();

        $this->submitCheckout($this->payload())->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count(), 'a stale discount must not be honoured after the bundle is gone');
    }

    // 8. Legitimate reviewed checkout still works.
    public function test_a_normal_reviewed_checkout_places_exactly_one_order(): void
    {
        $ids = $this->parkCart();
        $this->review($ids);

        $this->submitCheckout($this->payload())->assertRedirect(route('buyer.checkout.success', Order::query()->sole()));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, \DB::table('order_items')->count());
    }

    // 9. API checkout requires every review fingerprint, on a real bearer token.
    public function test_api_checkout_requires_the_review_fingerprints(): void
    {
        $ids = $this->parkCart();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;

        $this->withToken($token)->withHeader('Accept', 'application/json')
            ->postJson('/api/buyer/checkout', ['item_ids' => $ids, 'checkout_token' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_fingerprint', 'address_fingerprint', 'shipping_fingerprint', 'voucher_fingerprint']);
    }
}
