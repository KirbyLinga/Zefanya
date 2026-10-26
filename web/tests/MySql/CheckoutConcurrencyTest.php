<?php

namespace Tests\MySql;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Services\VariantSyncService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    public function createApplication()
    {
        try {
            return Preflight::application();
        } catch (\Throwable) {
            Preflight::halt();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function fixture(int $stock = 5): array
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => '100.00', 'stock_quantity' => $stock, 'status' => 'active']);

        return [$seller, $product, $product->variants()->sole()];
    }

    private function checkout(Product $product, ?int $variant, ?Buyer $buyer = null): array
    {
        $buyer ??= Buyer::factory()->create();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant, 'quantity' => 1]);
        $token = CheckoutToken::issue($buyer->id);

        return ['action' => 'checkout', 'buyer' => $buyer->id, 'items' => [$line->id], 'token' => $token->token];
    }

    private function integrity(int $stock, int $orders, int $cartLines, int $usedTokens): void
    {
        $this->assertSame($stock, (int) DB::table('product_variants')->sum('stock_quantity'));
        $this->assertSame($stock, (int) DB::table('products')->sum('stock_quantity'));
        $this->assertSame(0, DB::table('product_variants')->where('stock_quantity', '<', 0)->count());
        $this->assertDatabaseCount('orders', $orders);
        $this->assertDatabaseCount('seller_orders', $orders);
        $this->assertDatabaseCount('order_items', $orders);
        $this->assertDatabaseCount('payments', $orders);
        $this->assertDatabaseCount('cart_items', $cartLines);
        $this->assertSame($usedTokens, CheckoutToken::query()->where('used', true)->count());
        $this->assertSame($orders, InventoryTransaction::query()->where('reason', 'checkout')->count());
        foreach (Order::with(['sellerOrders.orderItems', 'sellerOrders.payment'])->get() as $order) {
            $subtotal = 0;
            $paid = 0;
            foreach ($order->sellerOrders as $sellerOrder) {
                $lines = $sellerOrder->orderItems->sum(fn ($item) => $item->price_minor * $item->quantity);
                $this->assertSame($lines, $sellerOrder->subtotal_minor);
                $this->assertSame('cod', $sellerOrder->payment->method->value);
                $this->assertSame('pending', $sellerOrder->payment->status->value);
                $this->assertSame($lines, $sellerOrder->payment->amount_minor);
                $subtotal += $lines;
                $paid += $sellerOrder->payment->amount_minor;
            }
            $this->assertSame($subtotal, $order->subtotal_minor);
            $this->assertSame(0, $order->discount_minor);
            $this->assertSame($paid, $order->total_minor);
        }
        foreach (InventoryTransaction::query()->where('reason', 'checkout')->get() as $ledger) {
            $this->assertSame(-1, $ledger->delta);
            $this->assertSame($ledger->quantity_before - 1, $ledger->quantity_after);
            $this->assertNotNull($ledger->variant_id);
            $this->assertNotNull($ledger->seller_order_id);
        }
    }

    private function noSqlErrors(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors'], 'Unexpected MySQL errors: '.json_encode($result));
            $this->assertArrayNotHasKey('exception', $result);
        }
    }

    public function test_last_available_unit_has_exactly_one_winner(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $a = $this->checkout($product, $variant->id) + ['hold' => 'product'];
        $b = $this->checkout($product, $variant->id) + ['watch' => 'product'];

        $results = Race::run($a, $b);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertSame(302, $results['b']['status']);
        $this->assertNotEmpty($results['b']['error']);
        $this->integrity(0, 1, 1, 1);
        $this->assertSame(10000, Order::query()->sole()->total_minor);
        $this->assertSame([0], InventoryTransaction::query()->where('reason', 'checkout')->pluck('quantity_after')->all());
    }

    public function test_simultaneous_duplicate_token_cannot_create_duplicate_records(): void
    {
        [, $product, $variant] = $this->fixture();
        $task = $this->checkout($product, $variant->id);

        $results = Race::run($task + ['hold' => 'token'], $task + ['watch' => 'token']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('already used', $results['b']['error']);
        $this->integrity(4, 1, 0, 1);
    }

    public function test_committed_price_change_rejects_stale_checkout_without_partial_writes(): void
    {
        [, $product, $variant] = $this->fixture();
        $task = $this->checkout($product, $variant->id);

        $results = Race::run(['action' => 'price', 'product' => $product->id, 'variant' => $variant->id],
            $task + ['watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('price changed', $results['b']['error']);
        $this->assertSame('125.99', $variant->fresh()->price);
        $this->integrity(5, 0, 1, 0);
    }

    public function test_committed_suspension_blocks_checkout_waiting_on_seller_lock(): void
    {
        [$seller, $product, $variant] = $this->fixture();
        $task = $this->checkout($product, $variant->id);

        $results = Race::run(['action' => 'suspend', 'seller' => $seller->id], $task + ['watch' => 'seller']);

        $this->noSqlErrors($results);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('seller', $results['b']['error']);
        $this->assertSame('suspended', $seller->fresh()->status);
        $this->integrity(5, 0, 1, 0);
    }

    public function test_simultaneous_adds_to_initially_empty_cart_preserve_both_quantities(): void
    {
        [, $product, $variant] = $this->fixture(20);
        $buyer = Buyer::factory()->create();
        $task = ['action' => 'add', 'buyer' => $buyer->id, 'product' => $product->id, 'variant' => $variant->id,
            'quantity' => 2, 'hold' => 'cartread'];

        $results = Race::run($task, $task, bothHeld: true);

        $this->integrity(20, 0, 1, 0);
        $this->assertDatabaseCount('checkout_tokens', 0);
        $this->noSqlErrors($results);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(4, CartItem::query()->sole()->quantity);
        $this->assertGreaterThanOrEqual(2, max(array_column($results, 'cart_reads')));
        $this->assertLessThanOrEqual(3, max(array_column($results, 'cart_reads')));
    }

    public function test_add_and_absolute_quantity_update_are_serializable(): void
    {
        [, $product, $variant] = $this->fixture(20);
        $buyer = Buyer::factory()->create();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);

        $results = Race::run(
            ['action' => 'add', 'buyer' => $buyer->id, 'product' => $product->id, 'variant' => $variant->id, 'quantity' => 2, 'hold' => 'cartread'],
            ['action' => 'update', 'buyer' => $buyer->id, 'item' => $line->id, 'quantity' => 5, 'watch' => 'cartwrite']);

        $this->noSqlErrors($results);
        $this->assertSame([200, 200], array_column($results, 'status'));
        // Update is an absolute assignment ordered after the locked add.
        $this->assertSame(5, $line->fresh()->quantity);
        $this->integrity(20, 0, 1, 0);
    }

    public function test_legacy_selection_becoming_ambiguous_while_waiting_is_rejected(): void
    {
        [, $product] = $this->fixture();
        $task = $this->checkout($product, null);

        $results = Race::run(['action' => 'variations', 'product' => $product->id], $task + ['watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('Choose a color or size', $results['b']['error']);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertNull(CartItem::query()->sole()->variant_id);
        $this->integrity(5, 0, 1, 0);
    }

    public function test_existing_ambiguous_legacy_line_is_rejected_during_another_transaction(): void
    {
        [$seller, $product, $variant] = $this->fixture();
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Green', 'Black']], ['name' => 'Size', 'values' => ['Small']]],
            [
                ['values' => ['Green', 'Small'], 'price' => '100.00', 'stock_quantity' => 2, 'is_available' => true],
                ['values' => ['Black', 'Small'], 'price' => '100.00', 'stock_quantity' => 3, 'is_available' => true],
            ], true);
        $task = $this->checkout($product, null);

        $results = Race::run(['action' => 'suspend', 'seller' => $seller->id], $task, rejectionWithoutLock: true);

        $this->noSqlErrors($results);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('choose a color or size', $results['b']['error']);
        $this->assertNull(CartItem::query()->sole()->variant_id);
        $this->integrity(5, 0, 1, 0);
    }

    public function test_simultaneous_first_adds_are_capped_at_variant_stock(): void
    {
        [, $product, $variant] = $this->fixture(3);
        $buyer = Buyer::factory()->create();
        $task = ['action' => 'add', 'buyer' => $buyer->id, 'product' => $product->id,
            'variant' => $variant->id, 'quantity' => 2, 'hold' => 'cartread'];

        $results = Race::run($task, $task, bothHeld: true);

        $this->noSqlErrors($results);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(3, CartItem::query()->sole()->quantity);
        $this->integrity(3, 0, 1, 0);
    }

    public function test_simultaneous_first_adds_keep_different_variants_separate(): void
    {
        [, $product, $variant] = $this->fixture(20);
        $second = $product->variants()->create(['price' => '100.00', 'stock_quantity' => 20, 'is_available' => true]);
        $product->syncVariantRollups();
        $buyer = Buyer::factory()->create();
        $base = ['action' => 'add', 'buyer' => $buyer->id, 'product' => $product->id, 'quantity' => 2, 'hold' => 'cartread'];

        $results = Race::run($base + ['variant' => $variant->id], $base + ['variant' => $second->id], bothHeld: true);

        $this->noSqlErrors($results);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame([$variant->id, $second->id], CartItem::query()->orderBy('variant_id')->pluck('variant_id')->all());
        $this->assertSame([2, 2], CartItem::query()->orderBy('variant_id')->pluck('quantity')->all());
        $this->assertSame(2, max(array_column($results, 'cart_count')));
        $this->assertSame(2, CartItem::query()->where('buyer_id', $buyer->id)->count());
        $this->integrity(40, 0, 2, 0);
    }

    public static function exhaustedConcurrencyErrors(): array
    {
        return ['deadlock' => [1213], 'lock timeout' => [1205]];
    }

    #[DataProvider('exhaustedConcurrencyErrors')]
    public function test_exhausted_retries_return_safe_503_without_partial_writes(int $driverError): void
    {
        [, $product, $variant] = $this->fixture(20);
        $buyer = Buyer::factory()->create();

        // Fault injection verifies exhaustion, not proof of simultaneous requests.
        $result = Race::single(['action' => 'add', 'buyer' => $buyer->id,
            'product' => $product->id, 'variant' => $variant->id, 'quantity' => 2,
            'inject_error' => $driverError]);

        $this->assertSame(503, $result['status']);
        $this->assertFalse($result['success']);
        $this->assertSame('Your cart is busy. Please try again.', $result['error']);
        $this->assertSame(3, $result['cart_reads']);
        $this->assertSame(3, $result['injected_failures']);
        $this->assertSame([], $result['sql_errors']);
        $this->assertDatabaseCount('checkout_tokens', 0);
        $this->integrity(20, 0, 0, 0);
    }

    protected function tearDown(): void
    {
        try {
            $snapshot = [
                'test' => $this->name(),
                'variant_stock' => DB::table('product_variants')->orderBy('id')->pluck('stock_quantity')->all(),
                'product_stock' => DB::table('products')->orderBy('id')->pluck('stock_quantity')->all(),
                'orders' => DB::table('orders')->count(), 'seller_orders' => DB::table('seller_orders')->count(),
                'order_items' => DB::table('order_items')->get(['variant_id', 'quantity', 'price_minor'])->all(),
                'payments' => DB::table('payments')->get(['amount_minor', 'method', 'status'])->all(),
                'ledger' => DB::table('inventory_transactions')->get(['variant_id', 'quantity_before', 'quantity_after', 'delta', 'reason'])->all(),
                'tokens_used' => DB::table('checkout_tokens')->where('used', true)->count(),
                'tokens_total' => DB::table('checkout_tokens')->count(),
                'cart' => DB::table('cart_items')->get(['product_id', 'variant_id', 'quantity'])->all(),
            ];
            file_put_contents(dirname(__DIR__, 2).'/.codex/t05-runs/'.$this->name().'.integrity.json',
                json_encode($snapshot, JSON_PRETTY_PRINT));
            Preflight::clean();
        } finally {
            parent::tearDown();
        }
    }
}
