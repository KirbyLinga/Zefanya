<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Category;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Seller\VariantSyncService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    use BootstrapsCheckoutCarriers;

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
        $this->bootstrapCheckoutCarriers($buyer, [$product->seller]);
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant, 'quantity' => 1]);
        $token = CheckoutToken::issue($buyer->id);

        return ['action' => 'checkout', 'buyer' => $buyer->id, 'items' => [$line->id], 'token' => $token->token,
            ...$this->checkoutCarrierPayload([$product->seller])];
    }

    /**
     * Produce genuine reviewed checkout state by rendering the real checkout
     * page for this task's buyer, exactly as CheckoutReviewGuardTest does.
     *
     * The application writes the parked selection, the one-time token and the
     * reviewed price fingerprint in that one request; the worker replays that
     * session. Fingerprints are never reconstructed by hand here, because a
     * hand-built hash does not match what CheckoutController::store() recomputes.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function reviewed(array $task): array
    {
        $buyer = Buyer::query()->findOrFail($task['buyer']);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.checkout.index', ['items' => $task['items']]))
            ->assertOk();

        $token = session(CheckoutController::TOKEN_KEY);
        // Private session key of CheckoutController; read back what the page wrote.
        $fingerprint = session()->get('checkout_reviewed_price');

        $this->assertIsString($token, 'the review page must issue a checkout token');
        $this->assertIsString($fingerprint, 'the review page must park a reviewed price fingerprint');
        $this->assertEqualsCanonicalizing($task['items'], session(CheckoutController::SESSION_KEY),
            'the parked selection must be the reviewed selection');

        // A real browser holds one live review; discard the token issued before
        // the page rendered so the fixture matches that flow.
        CheckoutToken::query()->where('buyer_id', $buyer->id)->where('token', '!=', $token)->delete();

        return array_replace($task, [
            'token' => $token,
            'reviewed_token' => $token,
            'reviewed_price' => $fingerprint,
        ]);
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
                $this->assertGreaterThanOrEqual(0, $sellerOrder->shipping_fee_minor);
                $this->assertSame($lines + $sellerOrder->shipping_fee_minor, $sellerOrder->payment->amount_minor);
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
        $this->assertSame(10000 + Order::query()->sole()->sellerOrders()->sole()->shipping_fee_minor, Order::query()->sole()->total_minor);
        $this->assertSame([0], InventoryTransaction::query()->where('reason', 'checkout')->pluck('quantity_after')->all());
    }

    public function test_checkout_waiting_on_an_ended_sale_rejects_the_stale_price(): void
    {
        [$seller, $product, $variant] = $this->fixture();
        $sale = StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'value' => 20]);
        $checkout = $this->checkout($product, $variant->id) + ['watch' => 'product'];

        $results = Race::run(['action' => 'end_sale', 'sale' => $sale->id, 'hold' => 'product'], $checkout);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('price changed', $results['b']['error']);
        $this->integrity(5, 0, 1, 0);
    }

    public function test_oversized_checkout_is_rejected_before_mysql_writes(): void
    {
        [, $product, $variant] = $this->fixture(50);
        $variant->update(['price' => '1000000.00']);
        $product->syncVariantRollups();
        $checkout = $this->checkout($product, $variant->id);
        CartItem::query()->whereKey($checkout['items'])->update(['quantity' => 50]);

        $result = Race::single($checkout);

        $this->noSqlErrors([$result]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('supported order amount', $result['error']);
        $this->integrity(50, 0, 1, 0);
    }

    public function test_seller_stock_edit_winning_the_product_lock_blocks_checkout(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $this->makeProductEditable($product);
        $checkout = $this->checkout($product, $variant->id) + ['watch' => 'product'];

        $results = Race::run(
            ['action' => 'edit_stock', 'product' => $product->id, 'quantity' => 0, 'hold' => 'product'],
            $checkout,
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('out of stock', $results['b']['error']);
        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'checkout')->count());
    }

    public function test_checkout_winning_the_product_lock_rejects_a_stale_seller_stock_edit(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $this->makeProductEditable($product);
        $checkout = $this->checkout($product, $variant->id) + ['hold' => 'product'];

        $results = Race::run($checkout,
            ['action' => 'edit_stock', 'product' => $product->id, 'quantity' => 5, 'watch' => 'product'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertContains('inventory_revision', $results['b']['validation_fields']);
        $this->integrity(0, 1, 0, 1);
    }

    private function makeProductEditable(Product $product): void
    {
        $product->update(['category_id' => Category::query()->create(['label' => 'Stock race', 'icon' => 'box'])->id]);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => 'test/stock-edit.jpg']);
    }

    public function test_overlapping_multi_seller_checkouts_leave_the_losing_cart_and_its_stock_intact(): void
    {
        [, $shared, $sharedVariant] = $this->fixture(1);
        [$sellerA, $productA, $variantA] = $this->fixture(1);
        [$sellerB, $productB, $variantB] = $this->fixture(1);
        $a = $this->checkout($shared, $sharedVariant->id) + ['hold' => 'product'];
        $b = $this->checkout($shared, $sharedVariant->id) + ['watch' => 'product'];
        $a = $this->appendCheckoutLine($a, $shared->seller, $sellerA, $productA, $variantA->id);
        $b = $this->appendCheckoutLine($b, $shared->seller, $sellerB, $productB, $variantB->id);

        $results = Race::run($a, $b);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertSame([0, 0, 1], [$sharedVariant->fresh()->stock_quantity, $variantA->fresh()->stock_quantity, $variantB->fresh()->stock_quantity]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('seller_orders', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(2, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertSame(1, CheckoutToken::query()->where('used', true)->count());
    }

    private function appendCheckoutLine(array $task, Seller $sharedSeller, Seller $seller, Product $product, int $variant): array
    {
        $this->bootstrapCheckoutCarriers(Buyer::query()->findOrFail($task['buyer']), [$sharedSeller, $seller]);
        $task['items'][] = CartItem::factory()->create(['buyer_id' => $task['buyer'], 'product_id' => $product->id,
            'variant_id' => $variant, 'quantity' => 1])->id;

        return array_replace($task, $this->checkoutCarrierPayload([$sharedSeller, $seller]));
    }

    public function test_simultaneous_duplicate_token_cannot_create_duplicate_records(): void
    {
        [, $product, $variant] = $this->fixture();
        $task = $this->checkout($product, $variant->id);

        $results = Race::run($task + ['hold' => 'token'], $task + ['watch' => 'buyer']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('already used', $results['b']['error']);
        $this->integrity(4, 1, 0, 1);
    }

    #[DataProvider('voucherLimits')]
    public function test_concurrent_orders_cannot_exceed_the_voucher_limit(bool $perBuyer): void
    {
        [, $first, $firstVariant] = $this->fixture();
        [, $second, $secondVariant] = $this->fixture();
        $buyer = Buyer::factory()->create();
        $voucher = Voucher::factory()->fixed(1000)->create($perBuyer ? ['per_buyer_limit' => 1] : ['usage_limit' => 1]);
        $state = ['vouchers' => ['platform' => $voucher->code]];
        $a = $this->checkout($first, $firstVariant->id, $buyer) + $state;
        $b = $this->checkout($second, $secondVariant->id, $perBuyer ? $buyer : null) + $state;
        $results = Race::run(
            $a + ['hold' => 'voucher'],
            $b + ['watch' => $perBuyer ? 'buyer' : 'voucher'],
        );

        $this->noSqlErrors($results);
        $this->assertSame([true, false], array_column($results, 'success'));
        $this->assertSame(1, $voucher->fresh()->times_used);
        $this->assertSame(1000, Order::query()->sole()->discount_minor);
        $this->assertSame(1, CheckoutToken::query()->where('used', true)->count());
    }

    public static function voucherLimits(): array
    {
        return ['global' => [false], 'per buyer' => [true]];
    }

    public function test_two_checkout_tokens_cannot_purchase_the_same_cart_line_twice(): void
    {
        [, $product, $variant] = $this->fixture();
        $first = $this->checkout($product, $variant->id);
        $second = ['token' => CheckoutToken::issue($first['buyer'])->token] + $first;
        $results = Race::run($first + ['hold' => 'token'], $second + ['watch' => 'buyer']);

        $this->noSqlErrors($results);
        $this->assertSame([true, false], array_column($results, 'success'));
        $this->assertStringContainsString('cart changed', $results['b']['error']);
        $this->integrity(4, 1, 0, 1);
    }

    public function test_checkout_and_cancellation_keep_the_voucher_counter_consistent(): void
    {
        [, $product, $variant] = $this->fixture();
        $voucher = Voucher::factory()->fixed(1000)->create(['usage_limit' => 2, 'times_used' => 1]);
        $original = Order::factory()->create(['status' => OrderStatus::Placed]);
        $parcel = SellerOrder::factory()->create([
            'order_id' => $original->id, 'status' => OrderStatus::Placed, 'platform_voucher_id' => $voucher->id,
        ]);
        $checkout = $this->checkout($product, $variant->id) + ['vouchers' => ['platform' => $voucher->code]];
        $results = Race::run(
            ['action' => 'voucher_cancel', 'buyer' => $original->buyer_id, 'parcel' => $parcel->id, 'hold' => 'voucher'],
            $checkout + ['watch' => 'voucher'],
        );

        $this->noSqlErrors($results);
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
        $this->assertSame(1, $voucher->fresh()->times_used);
        $this->assertSame(1000, Order::query()->whereKeyNot($original->id)->sole()->discount_minor);
    }

    public function test_concurrent_buyer_cancellation_restores_stock_once(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $checkout = $this->checkout($product, $variant->id);
        $this->assertTrue(Race::single($checkout)['success']);
        $parcel = SellerOrder::query()->sole();
        $cancel = ['action' => 'voucher_cancel', 'buyer' => $checkout['buyer'], 'parcel' => $parcel->id];

        $results = Race::run($cancel + ['hold' => 'order'], $cancel + ['watch' => 'order']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'cancel')->count());
    }

    public function test_buyer_cancellation_and_completion_serialize_siblings(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $checkout = $this->checkout($product, $variant->id);
        $this->assertTrue(Race::single($checkout)['success']);
        $placed = SellerOrder::query()->sole();
        $delivered = SellerOrder::factory()->create(['order_id' => $placed->order_id,
            'seller_id' => Seller::factory()->create()->id, 'status' => OrderStatus::Delivered]);
        $payment = Payment::factory()->create(['seller_order_id' => $delivered->id, 'amount_minor' => $delivered->subtotal_minor]);
        $payment->recordCodCollection($payment->amount_minor, 'rider', 1);

        $results = Race::run(
            ['action' => 'confirm_received', 'buyer' => $checkout['buyer'], 'order' => $placed->order_id, 'hold' => 'parcel'],
            ['action' => 'voucher_cancel', 'buyer' => $checkout['buyer'], 'parcel' => $placed->id, 'watch' => 'order'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success']);
        $this->assertSame(OrderStatus::Cancelled, $placed->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $delivered->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $placed->order->fresh()->status);
        $this->assertSame('order', $results['a']['first_order_lock']);
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'cancel')->count());
    }

    public function test_buy_now_waits_for_checkout_and_keeps_a_new_cart_line(): void
    {
        [, $product, $variant] = $this->fixture(3);
        $checkout = $this->checkout($product, $variant->id);
        $results = Race::run($checkout + ['hold' => 'cartread'],
            ['action' => 'buy_now', 'buyer' => $checkout['buyer'], 'product' => $product->id,
                'variant' => $variant->id, 'quantity' => 2, 'watch' => 'buyer'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success']);
        $this->assertSame(2, $variant->fresh()->stock_quantity);
        $line = CartItem::query()->sole();
        $this->assertNotContains($line->id, $checkout['items']);
        $this->assertSame(2, $line->quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_buy_now_racing_checkout_cannot_readd_a_sold_out_variant(): void
    {
        [, $product, $variant] = $this->fixture(1);
        $checkout = $this->checkout($product, $variant->id);
        $results = Race::run($checkout + ['hold' => 'cartread'],
            ['action' => 'buy_now', 'buyer' => $checkout['buyer'], 'product' => $product->id,
                'variant' => $variant->id, 'quantity' => 1, 'watch' => 'buyer'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertSame(422, $results['b']['status']);
        $this->integrity(0, 1, 0, 1);
    }

    public function test_bundle_creation_racing_checkout_rejects_the_stale_quote(): void
    {
        [$seller, $first, $variant] = $this->fixture(3);
        $second = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3, 'price' => '100.00']);
        $checkout = $this->checkout($first, $variant->id);
        $checkout['items'][] = CartItem::factory()->create(['buyer_id' => $checkout['buyer'], 'product_id' => $second->id,
            'variant_id' => $second->variants()->sole()->id, 'quantity' => 1])->id;
        // Review BOTH lines before the bundle exists, then let the bundle be
        // created while the checkout is already blocked on the product lock.
        $checkout = $this->reviewed($checkout);

        $results = Race::run(['action' => 'create_bundle', 'seller' => $seller->id, 'products' => [$first->id, $second->id],
            'hold' => 'promotion_insert'], $checkout + ['watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertDatabaseCount('seller_promotions', 1);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('price changed', $results['b']['error']);
        $this->integrity(6, 0, 2, 0);
    }

    public function test_bundle_removal_racing_checkout_rejects_the_stale_discount(): void
    {
        [$seller, $first, $variant] = $this->fixture(3);
        $second = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3, 'price' => '100.00']);
        $checkout = $this->checkout($first, $variant->id);
        $checkout['items'][] = CartItem::factory()->create(['buyer_id' => $checkout['buyer'], 'product_id' => $second->id,
            'variant_id' => $second->variants()->sole()->id, 'quantity' => 1])->id;
        $bundle = SellerPromotion::query()->create(['seller_id' => $seller->id,
            'kind' => PromotionKind::BundlePercent, 'name' => 'Bundle', 'percent' => 20,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $bundle->products()->attach([$first->id, $second->id]);
        // The buyer reviews the discounted quote; the bundle is deleted while
        // the checkout waits on the seller lock.
        $checkout = $this->reviewed($checkout);

        $results = Race::run(['action' => 'delete_bundle', 'seller' => $seller->id, 'promotion' => $bundle->id,
            'hold' => 'promotion_delete'], $checkout + ['watch' => 'seller']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertDatabaseCount('seller_promotions', 0);
        $this->assertFalse($results['b']['success'], 'a discount removed before the order committed must not be honoured');
        $this->assertStringContainsString('price changed', $results['b']['error']);
        $this->integrity(6, 0, 2, 0);
    }

    public function test_checkout_waits_for_cancellation_to_restore_the_same_product(): void
    {
        [, $product, $variant] = $this->fixture(2);
        $first = $this->checkout($product, $variant->id);
        $this->assertTrue(Race::single($first)['success']);
        $placed = SellerOrder::query()->sole();
        $second = $this->checkout($product, $variant->id);

        $results = Race::run(
            ['action' => 'voucher_cancel', 'buyer' => $first['buyer'], 'parcel' => $placed->id, 'hold' => 'product'],
            $second + ['watch' => 'product'],
        );

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success']);
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame([-1, 1, -1], InventoryTransaction::query()->orderBy('id')->pluck('delta')->all());
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('payments', 2);
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
            ['action' => 'update', 'buyer' => $buyer->id, 'item' => $line->id, 'quantity' => 5, 'watch' => 'cartread']);

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
