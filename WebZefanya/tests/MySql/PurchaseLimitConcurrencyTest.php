<?php

namespace Tests\MySql;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Real MySQL races (isolated zefanya_concurrency_test database only) for the
 * per-product purchase limits: simultaneous checkouts by one Buyer, and a
 * Seller changing a product's limits while a checkout is in flight. Uses the
 * existing lock order; no new locks are taken by the limit check.
 */
class PurchaseLimitConcurrencyTest extends TestCase
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

    /** @param  array<string, mixed>  $limits */
    private function product(Seller $seller, array $limits = []): Product
    {
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => '100.00', 'stock_quantity' => 5, 'status' => 'active']);
        $product->forceFill($limits)->save();

        return $product;
    }

    /** A ₱100.00 line of this variant for this Buyer and a fresh one-time token, as a worker checkout task. */
    private function checkout(Seller $seller, Buyer $buyer, ProductVariant $variant, bool $bootstrap = true): array
    {
        if ($bootstrap) {
            $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        }
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $variant->product_id,
            'variant_id' => $variant->id, 'quantity' => 1]);
        $token = CheckoutToken::issue($buyer->id);

        return ['action' => 'checkout', 'buyer' => $buyer->id, 'items' => [$line->id], 'token' => $token->token,
            ...$this->checkoutCarrierPayload([$seller])];
    }

    private function noSqlErrors(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors'], 'Unexpected MySQL errors: '.json_encode($result));
            $this->assertArrayNotHasKey('exception', $result);
        }
    }

    private function limits(array $overrides): array
    {
        return array_replace(['purchase_limit_enabled' => true, 'purchase_limit_max_quantity' => null, 'purchase_limit_max_order_minor' => null,
            'purchase_limit_max_active_minor' => null, 'purchase_limit_exempt_trusted' => false], $overrides);
    }

    public function test_two_simultaneous_checkouts_cannot_together_exceed_the_active_value_limit(): void
    {
        // Each ₱100 order of the product fits alone; together they would reach ₱200 against a ₱150 limit.
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_active_minor' => 15000]);
        $other = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '100.00', 'stock_quantity' => 5, 'is_available' => true]);
        $buyer = Buyer::factory()->create();
        $first = $this->checkout($seller, $buyer, $product->variants()->orderBy('id')->first());
        $second = $this->checkout($seller, $buyer, $other, bootstrap: false);

        $results = Race::run($first + ['hold' => 'token'], $second + ['watch' => 'buyer']);

        $this->noSqlErrors($results);
        $this->assertSame([true, false], array_column($results, 'success'));
        $this->assertStringContainsString('not yet delivered', (string) $results['b']['error']);
        $this->assertSame(1, Order::query()->where('buyer_id', $buyer->id)->count());
        $this->assertFalse(CheckoutToken::query()->where('token', $second['token'])->sole()->used);
        $this->assertModelExists(CartItem::query()->findOrFail($second['items'][0]));
    }

    public function test_a_limit_saved_first_applies_to_the_checkout_waiting_on_the_product_row(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = $this->product($seller);
        $buyer = Buyer::factory()->create();
        $checkout = $this->checkout($seller, $buyer, $product->variants()->sole());

        $results = Race::run(['action' => 'purchase_limits', 'seller' => $seller->id, 'product' => $product->id,
            'limits' => $this->limits(['purchase_limit_max_order_minor' => 5000]), 'hold' => 'product'],
            $checkout + ['watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('up to ₱50.00 of', (string) $results['b']['error']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5000, $product->fresh()->purchase_limit_max_order_minor);
    }

    public function test_a_checkout_already_holding_the_product_row_finishes_under_the_old_limits(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = $this->product($seller);
        $buyer = Buyer::factory()->create();
        $checkout = $this->checkout($seller, $buyer, $product->variants()->sole());

        $results = Race::run($checkout + ['hold' => 'product'],
            ['action' => 'purchase_limits', 'seller' => $seller->id, 'product' => $product->id,
                'limits' => $this->limits(['purchase_limit_max_order_minor' => 5000]), 'watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertTrue($results['a']['success'], 'Existing orders and in-flight checkouts are not affected by a later limit.');
        $this->assertTrue($results['b']['success']);
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertTrue($product->fresh()->purchase_limit_enabled);
        $this->assertSame(5000, $product->fresh()->purchase_limit_max_order_minor);
    }

    public function test_multi_seller_checkouts_by_two_buyers_keep_the_lock_order_and_finish(): void
    {
        // Both checkouts touch limited products at two shops; rows are locked in id order, so neither deadlocks.
        $a = Seller::factory()->create(['status' => 'approved']);
        $b = Seller::factory()->create(['status' => 'approved']);
        $productA = $this->product($a, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 50000]);
        $productB = $this->product($b, ['purchase_limit_enabled' => true, 'purchase_limit_max_active_minor' => 50000]);
        $tasks = [];
        foreach ([Buyer::factory()->create(), Buyer::factory()->create()] as $buyer) {
            $this->bootstrapCheckoutCarriers($buyer, [$a, $b]);
            $lines = [];
            foreach ([$productA, $productB] as $product) {
                $lines[] = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id,
                    'variant_id' => $product->variants()->sole()->id, 'quantity' => 1])->id;
            }
            $tasks[] = ['action' => 'checkout', 'buyer' => $buyer->id, 'items' => $lines,
                'token' => CheckoutToken::issue($buyer->id)->token, ...$this->checkoutCarrierPayload([$a, $b])];
        }

        $results = Race::run($tasks[0] + ['hold' => 'product'], $tasks[1] + ['watch' => 'product']);

        $this->noSqlErrors($results);
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame(4, OrderItem::query()->count());
    }
}
