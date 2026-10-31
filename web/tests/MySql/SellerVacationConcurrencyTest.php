<?php

namespace Tests\MySql;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\OrderItem;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class SellerVacationConcurrencyTest extends TestCase
{
    use BootstrapsCheckoutCarriers;

    private static bool $migrated = false;

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        // New fixtures only: do not clean or truncate even the disposable database.
    }

    private function fixture(): array
    {
        $seller = Seller::factory()->create();
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => '100.00', 'stock_quantity' => 5]);
        $variant = $product->variants()->sole();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $token = CheckoutToken::issue($buyer->id);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        return [$seller, $product, $variant, $line, $token, [
            'action' => 'checkout', 'buyer' => $buyer->id, 'items' => [$line->id], 'token' => $token->token,
            ...$this->checkoutCarrierPayload([$seller]),
        ]];
    }

    private function assertOverlap(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
            $this->assertArrayNotHasKey('exception', $result);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }

    public function test_committed_vacation_enable_blocks_checkout_waiting_for_the_seller_row(): void
    {
        [$seller, $product, $variant, $line, $token, $checkout] = $this->fixture();
        $results = Race::run(['action' => 'vacation', 'seller' => $seller->id, 'enabled' => true, 'hold' => 'seller'], $checkout + ['watch' => 'seller']);
        $this->assertOverlap($results);
        $this->assertTrue($results['a']['success']);
        $this->assertFalse($results['b']['success']);
        $this->assertStringContainsString('vacation', $results['b']['error']);
        $this->assertTrue($seller->fresh()->is_on_vacation);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertFalse($token->fresh()->used);
        $this->assertModelExists($line);
        $this->assertSame(0, OrderItem::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, InventoryTransaction::query()->where('product_id', $product->id)->count());
    }

    public function test_vacation_enable_waits_until_an_already_locked_checkout_commits(): void
    {
        [$seller, $product, $variant, $line, $token, $checkout] = $this->fixture();
        $results = Race::run($checkout + ['hold' => 'seller'], ['action' => 'vacation', 'seller' => $seller->id, 'enabled' => true, 'watch' => 'seller']);
        $this->assertOverlap($results);
        $this->assertTrue($results['a']['success']);
        $this->assertTrue($results['b']['success']);
        $this->assertTrue($seller->fresh()->is_on_vacation);
        $this->assertSame(4, $variant->fresh()->stock_quantity);
        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertTrue($token->fresh()->used);
        $this->assertModelMissing($line);
        $item = OrderItem::query()->where('product_id', $product->id)->sole();
        $this->assertSame(10000, $item->price_minor);
        $this->assertSame('cod', $item->sellerOrder->payment->method->value);
        $this->assertSame(1, InventoryTransaction::query()->where('product_id', $product->id)->where('reason', 'checkout')->count());
    }

    public function test_shared_vacation_checks_preserve_concurrent_cart_add_stock_capping(): void
    {
        [$seller, $product, $variant, $line] = $this->fixture();
        $buyerId = $line->buyer_id;
        $line->delete();
        $task = ['action' => 'add', 'buyer' => $buyerId, 'product' => $product->id, 'variant' => $variant->id, 'quantity' => 3, 'hold' => 'cartread'];
        $results = Race::run($task, $task, bothHeld: true);
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(5, CartItem::query()->where('buyer_id', $buyerId)->where('product_id', $product->id)->sole()->quantity);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
    }
}
