<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Slice-0 hardening: a double-click on "Place Order" must not create a
 * second order. The guard is a one-time form token consumed INSIDE the
 * order DB transaction, so even two racing requests produce one order.
 */
class CheckoutDoubleSubmitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create();
        $this->product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'price' => 100.00,
            'stock_quantity' => 5,
        ]);
    }

    private function parkCheckout(): void
    {
        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]);

        session([
            CheckoutController::SESSION_KEY => [CartItem::query()->sole()->id],
            'cart_voucher' => null,
        ]);
    }

    public function test_a_second_submit_with_a_consumed_token_does_not_create_a_second_order(): void
    {
        $this->parkCheckout();

        // The checkout page issued exactly one token, stored in the session.
        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $token->token]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect();

        $this->assertSame(1, Order::query()->count(), 'first submit places one order');

        // The token is consumed now — replay it (double-click / double POST).
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, Order::query()->count(), 'replayed token must NOT create a second order');
        $this->assertSame(1, \DB::table('order_items')->count(), 'no duplicate order line');
    }

    public function test_the_token_is_consumed_inside_the_order_transaction(): void
    {
        $this->parkCheckout();

        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $token->token]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect();

        $this->assertTrue($token->refresh()->used, 'successful order marks the token used');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_stock_is_decremented_exactly_once_across_a_double_submit(): void
    {
        $this->parkCheckout();

        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $token->token]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $token->token]);

        // Second (racing/double-clicked) request with the SAME token.
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $token->token]);

        // 5 − 2 = 3, not 5 − 2 − 2 = 1. The variant-backed stock rollup must
        // also reflect exactly one decrement.
        $this->assertSame(3, $this->product->refresh()->stock_quantity);
    }

    public function test_a_fresh_token_from_a_new_checkout_page_still_works(): void
    {
        $this->parkCheckout();

        $first = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $first->token]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $first->token])
            ->assertRedirect();

        // A NEW checkout visit issues a NEW token and places a NEW order —
        // the guard only blocks replays of the SAME token.
        $second = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $second->token]);
        $this->parkCheckout();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => $second->token])
            ->assertRedirect();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_checkout_page_renders_the_token_field(): void
    {
        $this->parkCheckout();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('name="checkout_token"', false);

        // Rendering the page issued a fresh token into the session AND the
        // tokens table.
        $sessionToken = session(CheckoutController::TOKEN_KEY);
        $this->assertNotNull($sessionToken);
        $this->assertDatabaseHas('checkout_tokens', [
            'buyer_id' => $this->buyer->id,
            'token' => $sessionToken,
            'used' => false,
        ]);
    }
}
