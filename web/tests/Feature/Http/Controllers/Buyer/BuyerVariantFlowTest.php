<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Services\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BuyerVariantFlowTest extends TestCase
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
            ->post(route('buyer.checkout.store'))
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

    public function test_pre_variant_cart_lines_still_checkout_through_the_default_variant(): void
    {
        // Legacy line: created before variants, no variant_id.
        $legacy = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => 1,
        ]);

        // The normalization in placeOrder() points it at the Default variant
        // (lowest id) — for this product that's 'Red,M' (first generated).
        $default = ProductVariant::query()->where('product_id', $this->product->id)->orderBy('id')->first();

        session([CheckoutController::SESSION_KEY => [$legacy->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect();

        $default->refresh();
        $this->assertSame(3, $default->stock_quantity, '4 − 1 through the Default variant');

        $this->assertSame(
            $default->id,
            OrderItem::query()->sole()->variant_id,
        );
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
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
        $variant->refresh();
        $this->assertSame(1, $variant->stock_quantity, 'rejected checkout must not consume stock');
    }
}
