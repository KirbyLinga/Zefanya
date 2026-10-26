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
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Slice-0 hardening: a double-click on "Place Order" must not create a
 * second order. The guard is a one-time form token consumed INSIDE the
 * order DB transaction, so even two racing requests produce one order.
 */
class CheckoutDoubleSubmitTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
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

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
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

    /** @return array<string, mixed> */
    private function storePayload(mixed $checkoutToken): array
    {
        return [
            'checkout_token' => $checkoutToken,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ];
    }

    public function test_a_second_submit_with_a_consumed_token_does_not_create_a_second_order(): void
    {
        $this->parkCheckout();

        // The checkout page issued exactly one token, stored in the session.
        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $token->token]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), $this->storePayload($token->token))
            ->assertRedirect();

        $this->assertSame(1, Order::query()->count(), 'first submit places one order');

        $this->parkCheckout(); // Ensure replay reaches the token guard with a valid selection.

        // The token is consumed now — replay it (double-click / double POST).
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), $this->storePayload($token->token))
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
            ->post(route('buyer.checkout.store'), $this->storePayload($token->token))
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
            ->post(route('buyer.checkout.store'), $this->storePayload($token->token));

        $this->parkCheckout();
        // Second (racing/double-clicked) request with the SAME token.
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), $this->storePayload($token->token));

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
            ->post(route('buyer.checkout.store'), $this->storePayload($first->token))
            ->assertRedirect();

        // A NEW checkout visit issues a NEW token and places a NEW order —
        // the guard only blocks replays of the SAME token.
        $second = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $second->token]);
        $this->parkCheckout();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), $this->storePayload($second->token))
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

    public static function invalidTokenCases(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'empty', 'whitespace', 'array', 'malformed', 'unknown', 'expired', 'boundary', 'foreign', 'consumed']);
    }

    #[DataProvider('invalidTokenCases')]
    public function test_invalid_tokens_create_no_financial_or_stock_records(string $case): void
    {
        $this->freezeTime();
        $this->parkCheckout();
        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::TOKEN_KEY => $token->token]);
        $value = match ($case) {
            'empty' => '', 'whitespace' => '   ', 'array' => ['bad'],
            'malformed' => 'not-a-token', 'unknown' => str_repeat('a', 64),
            'foreign' => CheckoutToken::issue(Buyer::factory()->create()->id)->token,
            default => $token->token,
        };
        if ($case === 'expired' || $case === 'boundary') {
            $token->update(['expires_at' => $case === 'expired' ? now()->subSecond() : now()]);
        }
        if ($case === 'consumed') {
            $token->update(['used' => true]);
        }

        $payload = $case === 'missing'
            ? $this->checkoutCarrierPayload([$this->seller])
            : $this->storePayload($value);

        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $payload)
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');

        foreach (['orders', 'seller_orders', 'order_items', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame($case === 'consumed', $token->fresh()->used);
    }

    public function test_missing_token_table_fails_closed(): void
    {
        $this->parkCheckout();
        // This table exists only in the verified disposable SQLite :memory: test DB.
        Schema::drop('checkout_tokens');

        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $this->storePayload(str_repeat('a', 64)))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        $this->get(route('buyer.checkout.index'))->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');

        foreach (['orders', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
    }

    public function test_failed_placement_rolls_back_the_token_claim(): void
    {
        $this->parkCheckout();
        $token = CheckoutToken::issue($this->buyer->id);
        $drained = false;
        Product::retrieved(function ($product) use (&$drained): void {
            if (! $drained && $product->id === $this->product->id) {
                $drained = true;
                \Illuminate\Support\Facades\DB::table('product_variants')->where('product_id', $product->id)->update(['stock_quantity' => 0]);
            }
        });

        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $this->storePayload($token->token))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');

        $this->assertTrue($drained);
        $this->assertFalse($token->fresh()->used);
        foreach (['orders', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(1, CartItem::query()->count());
    }
}
