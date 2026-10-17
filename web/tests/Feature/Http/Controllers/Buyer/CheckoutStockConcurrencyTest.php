<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CartSummary;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Checkout must gate AND decrement stock against the rows it locked, never
 * against the cart-item copies loaded before the lock. Otherwise two buyers
 * can each pass the check on the last unit and the second write overwrites
 * the first — the shop oversells.
 */
class CheckoutStockConcurrencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Create a product plus its auto Default variant at a known stock level.
     *
     * @return array{0: Product, 1: ProductVariant}
     */
    private function productWithDefaultVariant(Seller $seller, int $stock, float $price = 100.00): array
    {
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Race Widget',
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
        ]);

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->firstOrFail();

        return [$product, $variant];
    }

    /**
     * Regression for the stale read: the line is loaded with its variant (so
     * the in-memory copy holds stock = 1), then the DB row is drained to 0
     * before the locked check runs. The order must be rejected against the
     * LOCKED row (0), not the stale pre-lock copy (1).
     *
     * The interleaving is driven through the real placement unit because on
     * SQLite (the test DB) `lockForUpdate()` is a no-op and there is no second
     * connection to stage the race through HTTP. Loading the line, draining
     * the row, then calling placeOrder() is exactly the ordering a concurrent
     * checkout produces in production.
     */
    public function test_stale_pre_lock_variant_read_cannot_oversell(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 1);

        $cart = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        // Pre-lock load: this copy holds stock = 1.
        $items = CartItem::query()
            ->whereKey($cart->id)
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant'])
            ->get();

        $summary = new CartSummary($items, null, null);

        // A concurrent checkout drains the row AFTER the load but BEFORE the lock.
        $variant->update(['stock_quantity' => 0]);

        $this->assertSame(1, (int) $items->first()->variant->stock_quantity, 'pre-lock copy still holds the stale stock');
        $this->assertSame(0, (int) $variant->fresh()->stock_quantity, 'the DB row is already drained');

        $placeOrder = new ReflectionMethod(CheckoutController::class, 'placeOrder');
        $placeOrder->setAccessible(true);

        $rejected = false;

        try {
            $placeOrder->invoke(app(CheckoutController::class), $buyer, $items, $summary, null);
        } catch (CheckoutException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'the locked stock (0) must reject the order, not the stale copy (1)');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(0, (int) $variant->fresh()->stock_quantity, 'no negative / phantom stock');
        $this->assertSame(1, CartItem::query()->count(), 'the rejected line survives in the cart');
    }

    public function test_two_buyers_racing_for_the_last_unit_only_one_wins(): void
    {
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 1);

        $buyerA = Buyer::factory()->create();
        $buyerB = Buyer::factory()->create();

        $cartA = CartItem::factory()->create([
            'buyer_id' => $buyerA->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $cartB = CartItem::factory()->create([
            'buyer_id' => $buyerB->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        // Buyer A checks out first and takes the last unit.
        session([CheckoutController::SESSION_KEY => [$cartA->id]]);
        $this->actingAs($buyerA, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.checkout.success', Order::query()->sole()));

        // Buyer B checks out next: the locked row is now 0.
        session([CheckoutController::SESSION_KEY => [$cartB->id]]);
        $this->actingAs($buyerB, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, Order::query()->count(), 'exactly one order wins the last unit');
        $this->assertSame(0, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(1, CartItem::query()->count(), 'the loser keeps its cart line');
    }

    public function test_normal_checkout_decrements_locked_stock_and_ledgers_correct_values(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 5);

        $cart = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        session([CheckoutController::SESSION_KEY => [$cart->id]]);
        $this->actingAs($buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect();

        $this->assertSame(3, (int) $variant->fresh()->stock_quantity, '5 − 2');
        $this->assertSame(3, (int) $product->fresh()->stock_quantity, 'product rollup follows the variant');

        $ledger = InventoryTransaction::query()->where('reason', 'checkout')->sole();
        $this->assertSame($variant->id, $ledger->variant_id);
        $this->assertSame(5, $ledger->quantity_before);
        $this->assertSame(3, $ledger->quantity_after);
        $this->assertSame(-2, $ledger->delta);
    }

    /**
     * Legacy cart lines (variant_id null) must still lock the product's Default
     * variant before the stock gate, bind that locked instance onto the line,
     * and use it for the order snapshot + ledger — never the null pre-lock
     * relation.
     */
    public function test_legacy_cart_item_without_variant_id_locks_and_decrements_default_variant(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 4);

        $cart = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => 1,
        ]);

        $this->assertNull($cart->variant_id);

        session([CheckoutController::SESSION_KEY => [$cart->id]]);
        $this->actingAs($buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.checkout.success', Order::query()->sole()));

        $this->assertSame(3, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);

        $orderItem = OrderItem::query()->sole();
        $this->assertSame($variant->id, $orderItem->variant_id);

        $ledger = InventoryTransaction::query()->where('reason', 'checkout')->sole();
        $this->assertSame($variant->id, $ledger->variant_id);
        $this->assertSame(4, $ledger->quantity_before);
        $this->assertSame(3, $ledger->quantity_after);
        $this->assertSame(-1, $ledger->delta);
    }

    /**
     * Stale product rollup on a legacy line must not pass when the locked
     * Default variant (or locked product stock) is already drained.
     */
    public function test_legacy_cart_item_rejects_stale_pre_lock_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 1);

        $cart = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => 1,
        ]);

        $items = CartItem::query()
            ->whereKey($cart->id)
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant'])
            ->get();

        $summary = new CartSummary($items, null, null);

        $variant->update(['stock_quantity' => 0]);
        $product->update(['stock_quantity' => 0]);

        $this->assertSame(1, (int) $items->first()->product->stock_quantity, 'pre-lock product copy is stale');

        $placeOrder = new ReflectionMethod(CheckoutController::class, 'placeOrder');
        $placeOrder->setAccessible(true);

        $rejected = false;

        try {
            $placeOrder->invoke(app(CheckoutController::class), $buyer, $items, $summary, null);
        } catch (CheckoutException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, (int) $variant->fresh()->stock_quantity);
    }

    /**
     * Two lines that resolve to the same locked Default variant must share one
     * remaining pool. Otherwise each passes stock >= qty against the full
     * balance and the second decrement drives stock negative.
     *
     * Reproduced with one explicit Default line + one legacy (null) line —
     * unique(buyer, product, variant) does not collapse those two shapes.
     */
    public function test_multiple_lines_sharing_a_variant_cannot_oversell_combined_quantity(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        [$product, $variant] = $this->productWithDefaultVariant($seller, 1);

        $explicit = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $legacy = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => 1,
        ]);

        $items = CartItem::query()
            ->whereKey([$explicit->id, $legacy->id])
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant'])
            ->orderBy('id')
            ->get();

        $summary = new CartSummary($items, null, null);

        $placeOrder = new ReflectionMethod(CheckoutController::class, 'placeOrder');
        $placeOrder->setAccessible(true);

        $rejected = false;

        try {
            $placeOrder->invoke(app(CheckoutController::class), $buyer, $items, $summary, null);
        } catch (CheckoutException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'combined qty 2 against stock 1 must fail under the lock');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(1, (int) $variant->fresh()->stock_quantity, 'stock must stay at 1 — never −1');
        $this->assertSame(2, CartItem::query()->count());
    }

    /**
     * A TRUE two-connection race needs a database with real row locks. The
     * suite runs on SQLite (:memory:, see phpunit.xml), which ignores
     * `lockForUpdate()`, so the race cannot be reproduced here — and faking
     * it would assert nothing. The single-connection stale-read test above
     * exercises the same code path on SQLite; run the suite with
     * DB_CONNECTION=mysql to drive real FOR UPDATE contention.
     */
    public function test_true_concurrent_checkouts_do_not_oversell(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs MySQL: SQLite ignores row locks, so lockForUpdate() is a no-op.');
        }

        $this->markTestIncomplete('Add the two-connection FOR UPDATE race when a MySQL test connection is available.');
    }
}
