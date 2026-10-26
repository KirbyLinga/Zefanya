<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CartSummary;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Checkout must gate AND decrement stock against the rows it locked, never
 * against the cart-item copies loaded before the lock. Otherwise two buyers
 * can each pass the check on the last unit and the second write overwrites
 * the first — the shop oversells.
 */
class CheckoutStockConcurrencyTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
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
            $placeOrder->invoke(app(CheckoutController::class), $buyer, $items, $summary, CheckoutToken::issue($buyer->id)->token);
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

        $this->bootstrapCheckoutCarriers($buyerA, [$seller]);
        $this->bootstrapCheckoutCarriers($buyerB, [$seller]);

        // Buyer A checks out first and takes the last unit.
        session([CheckoutController::SESSION_KEY => [$cartA->id]]);
        $this->actingAs($buyerA, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($buyerA->id)->token,
                ...$this->checkoutCarrierPayload([$seller]),
            ])
            ->assertRedirect(route('buyer.checkout.success', Order::query()->sole()));

        // Buyer B checks out next: the locked row is now 0.
        session([CheckoutController::SESSION_KEY => [$cartB->id]]);
        $this->actingAs($buyerB, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($buyerB->id)->token,
                ...$this->checkoutCarrierPayload([$seller]),
            ])
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

        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        session([CheckoutController::SESSION_KEY => [$cart->id]]);
        $this->actingAs($buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($buyer->id)->token,
                ...$this->checkoutCarrierPayload([$seller]),
            ])
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

        // The dedicated runner verifies identity/grants before migrating and
        // uses separate HTTP worker processes with an observed MySQL lock wait.
        $process = new Process([
            PHP_BINARY, base_path('tests/MySql/run.php'),
            '--filter=test_last_available_unit_has_exactly_one_winner', '--testdox',
        ], base_path());
        $process->setTimeout(90);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (1 test,', $process->getOutput());
    }
}
