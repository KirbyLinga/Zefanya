<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Services\CartSummary;
use App\Services\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class BuyerVariantFlowTest extends TestCase
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
            'name' => 'Variant Hoodie',
            'price' => 100.00,
            'stock_quantity' => 10,
            'status' => 'active',
        ]);

        // Two combos: Red/M (stock 4) and Red/L (stock 6). No disabled rows.
        app(VariantSyncService::class)->sync(
            $this->product,
            [['name' => 'Color', 'values' => ['Red']], ['name' => 'Size', 'values' => ['M', 'L']]],
            [
                ['values' => ['Red', 'M'], 'price' => 100.00, 'stock_quantity' => 4, 'is_available' => true],
                ['values' => ['Red', 'L'], 'price' => 120.00, 'stock_quantity' => 6, 'is_available' => true],
            ],
            true,
        );

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
    }

    /** @return array<string, mixed> */
    private function storePayload(string $token): array
    {
        return [
            'checkout_token' => $token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ];
    }

    /**
     * Look up a generated variant by its combination signature ("Red,L") —
     * generated rows carry no SKU unless the seller submits one.
     */
    private function variant(string $signature): ProductVariant
    {
        $values = collect(explode(',', $signature))->map(fn ($v) => trim($v))->all();

        return ProductVariant::query()
            ->where('product_id', $this->product->id)
            ->whereHas('optionValues', fn ($q) => $q->whereIn('value', $values), '=', count($values))
            ->firstOrFail();
    }

    public function test_add_to_cart_targets_the_chosen_variant(): void
    {
        $variant = $this->variant('Red,L');

        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), [
                'product_id' => $this->product->id,
                'variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $line = CartItem::query()->sole();
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame(2, $line->quantity);
    }

    public function test_cart_line_price_uses_the_variant_price(): void
    {
        $variant = $this->variant('Red,L'); // ₱120.00

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $line = CartItem::query()->sole();
        $this->assertSame(24000, $line->lineTotalCentavos(), '2 × ₱120.00 = ₱240.00');
        $this->assertSame('₱240.00', $line->formattedLineTotal());
    }

    public function test_cart_quantity_is_capped_at_variant_stock(): void
    {
        $variant = $this->variant('Red,M'); // stock 4

        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), [
                'product_id' => $this->product->id,
                'variant_id' => $variant->id,
                'quantity' => 10,
            ])
            ->assertOk();

        $this->assertSame(4, CartItem::query()->sole()->quantity, 'capped at variant stock, not product stock');
    }

    public function test_disabled_combination_cannot_be_added(): void
    {
        $variant = $this->variant('Red,M');
        $variant->update(['is_available' => false]);

        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), [
                'product_id' => $this->product->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_checkout_decrements_variant_stock_and_snapshots_variant_price(): void
    {
        $variantM = $this->variant('Red,M');
        $variantL = $this->variant('Red,L');

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $variantM->id,
            'quantity' => 2, // 2 × ₱100.00
        ]);
        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $variantL->id,
            'quantity' => 1, // 1 × ₱120.00
        ]);

        // Park the selection the way CheckoutController@index does.
        $ids = CartItem::query()->pluck('id')->all();
        CheckoutController::SESSION_KEY
            ? session([CheckoutController::SESSION_KEY => $ids])
            : null;

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), $this->storePayload(CheckoutToken::issue($this->buyer->id)->token))
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(32000, $order->subtotal_minor, '2×10000 + 1×12000 = ₱320.00');

        $variantM->refresh();
        $variantL->refresh();
        $this->assertSame(2, $variantM->stock_quantity, '4 − 2');
        $this->assertSame(5, $variantL->stock_quantity, '6 − 1');

        // Rollup follows: 2 + 5 = 7.
        $this->product->refresh();
        $this->assertSame(7, $this->product->stock_quantity);

        // Ledger is per-variant.
        $this->assertSame(2, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertSame(
            [$variantM->id, $variantL->id],
            InventoryTransaction::query()->where('reason', 'checkout')->orderBy('id')->pluck('variant_id')->all(),
        );

        // Line snapshots: variant id + variant price.
        $items = OrderItem::query()->orderBy('price_minor')->get();
        $this->assertSame([10000, 12000], $items->pluck('price_minor')->all());
        $this->assertSame(
            [$variantM->id, $variantL->id],
            OrderItem::query()->orderBy('price_minor')->pluck('variant_id')->all(),
        );

        // Cart lines are consumed.
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_ambiguous_legacy_cart_line_requires_reselection_and_preserves_other_items(): void
    {
        $legacy = $this->legacyLine($this->product);
        $other = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant('Red,L')->id, 'quantity' => 2]);
        $before = $this->product->variants()->orderBy('id')->pluck('stock_quantity')->all();
        $this->assertFalse($legacy->fresh()->isAvailable());
        $this->assertSame(0, $legacy->fresh()->availableQuantity());
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.cart.index'))->assertOk()->assertSee('Choose a color or size on the product page first.');
        $this->rejectLegacy($legacy);
        $this->assertSame($before, $this->product->variants()->orderBy('id')->pluck('stock_quantity')->all());
        $this->assertSame(2, $other->fresh()->quantity);
        $this->assertNull($legacy->fresh()->variant_id);
        foreach (['buyer.cart.add', 'buyer.checkout.buy-now'] as $route) {
            $this->postJson(route($route), ['product_id' => $this->product->id, 'quantity' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        }
    }

    public function test_one_remaining_option_bearing_variant_still_requires_explicit_selection(): void
    {
        $this->variant('Red,L')->delete();
        $this->rejectLegacy($this->legacyLine($this->product));
    }

    public function test_one_available_variant_among_multiple_does_not_allow_legacy_fallback(): void
    {
        $this->variant('Red,L')->update(['is_available' => false]);
        $this->rejectLegacy($this->legacyLine($this->product));
    }

    public function test_genuine_optionless_default_legacy_line_uses_variant_price_and_stock(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => '25.50', 'stock_quantity' => 5]);
        $variant = $product->variants()->sole();
        $legacy = $this->legacyLine($product, 2);
        // Rollups are advisory: legacy lines must read the actual Default SKU.
        DB::table('products')->where('id', $product->id)->update(['price' => '1.00', 'stock_quantity' => 99]);
        $this->assertTrue($legacy->fresh()->isAvailable());
        $this->assertSame(5, $legacy->fresh()->availableQuantity());
        $this->assertSame(2550, $legacy->fresh()->unitPriceCentavos());
        session([CheckoutController::SESSION_KEY => [$legacy->id]]);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $this->storePayload(CheckoutToken::issue($this->buyer->id)->token))->assertSessionMissing('error');
        $this->assertSame($variant->id, OrderItem::query()->sole()->variant_id);
        $this->assertSame(2550, OrderItem::query()->sole()->price_minor);
        $this->assertSame(10000, Order::query()->sole()->total_minor);
        $this->assertSame(10000, Payment::query()->sole()->amount_minor);
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(3, $product->fresh()->stock_quantity);
    }

    public function test_disabled_or_missing_default_cannot_fallback_to_product_rollup(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 5]);
        $variant = $product->variants()->sole();
        $variant->update(['is_available' => false]);
        $legacy = $this->legacyLine($product);
        $this->rejectLegacy($legacy, false);
        $variant->delete();
        $this->rejectLegacy($legacy);
    }

    public function test_new_variant_between_precheck_and_locks_rejects_legacy_line(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 5]);
        $variant = $product->variants()->sole();
        $legacy = $this->legacyLine($product);
        // Stage the exact pre-lock snapshot then introduce another SKU.
        $items = CartItem::query()->whereKey($legacy->id)->with(['product.seller', 'product.variants.optionValues', 'variant'])->get();
        $summary = new CartSummary($items);
        DB::table('product_variants')->insert(['product_id' => $product->id, 'price' => '100.00', 'stock_quantity' => 5, 'is_available' => true, 'created_at' => now(), 'updated_at' => now()]);
        $token = CheckoutToken::issue($this->buyer->id);
        try {
            (new ReflectionMethod(CheckoutController::class, 'placeOrder'))->invoke(app(CheckoutController::class), $this->buyer, $items, $summary, $token->token);
            $this->fail('Ambiguity under the lock must reject checkout.');
        } catch (CheckoutException $exception) {
            $this->assertStringContainsString('Choose a color or size', $exception->getMessage());
        }
        $this->assertFalse($token->fresh()->used);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertModelExists($legacy);
    }

    public function test_legacy_and_explicit_default_lines_cannot_combined_oversell(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 3]);
        $variant = $product->variants()->sole();
        $legacy = $this->legacyLine($product, 2);
        $explicit = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::SESSION_KEY => [$legacy->id, $explicit->id]]);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $this->storePayload($token->token))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertFalse($token->fresh()->used);
        $this->assertModelExists($legacy);
        $this->assertModelExists($explicit);
    }

    private function legacyLine(Product $product, int $quantity = 1): CartItem
    {
        return CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => null, 'quantity' => $quantity]);
    }

    private function rejectLegacy(CartItem $line, bool $reselection = true): void
    {
        $this->assertFalse($line->fresh()->isAvailable());
        $token = CheckoutToken::issue($this->buyer->id);
        session([CheckoutController::SESSION_KEY => [$line->id]]);
        $response = $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        if ($reselection) {
            $response->assertSessionHas('error', fn ($message) => str_contains($message, 'choose a color or size'));
        }
        foreach (['orders', 'order_items', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertFalse($token->fresh()->used);
        $this->assertModelExists($line);
    }

    public function test_variant_going_out_of_stock_mid_checkout_is_rejected(): void
    {
        $variant = $this->variant('Red,M');

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        // Race: the variant drains between add-to-cart and checkout.
        $variant->update(['stock_quantity' => 1]);

        session([CheckoutController::SESSION_KEY => [CartItem::query()->sole()->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), ['checkout_token' => CheckoutToken::issue($this->buyer->id)->token])
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
        $variant->refresh();
        $this->assertSame(1, $variant->stock_quantity, 'rejected checkout must not consume stock');
    }
}
