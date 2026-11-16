<?php

namespace Tests\MySql;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Services\Seller\VariantSyncService;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class InventoryOversellingConcurrencyTest extends TestCase
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
        // Only add isolated fixtures; never clear existing application or test data.
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_sel_inv_006_final_available_sku_has_one_winner_and_no_overselling(bool $hasVariants): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 1, 'price' => '100.00']);
        $other = null;
        if ($hasVariants) {
            app(VariantSyncService::class)->sync($product, [['name' => 'Size', 'values' => ['M', 'L']]], [
                ['values' => ['M'], 'price' => '100.00', 'stock_quantity' => 1, 'weight_grams' => 500],
                ['values' => ['L'], 'price' => '100.00', 'stock_quantity' => 5, 'weight_grams' => 500],
            ], true);
            $other = $product->variants()->orderBy('id')->get()->last();
        }
        $variant = $product->variants()->orderBy('id')->firstOrFail();
        $tasks = $buyers = $tokens = $lines = [];
        foreach (['a', 'b'] as $name) {
            $buyer = Buyer::factory()->create();
            $this->bootstrapCheckoutCarriers($buyer, [$seller]);
            $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
            $token = CheckoutToken::issue($buyer->id);
            $buyers[$name] = $buyer;
            $tokens[$name] = $token;
            $lines[$name] = $line;
            $tasks[$name] = ['action' => 'checkout', 'buyer' => $buyer->id, 'items' => [$line->id], 'token' => $token->token, ...$this->checkoutCarrierPayload([$seller])];
        }
        $result = Race::run($tasks['a'] + ['hold' => 'product'], $tasks['b'] + ['watch' => 'product']);
        $this->assertTrue($result['a']['success']);
        $this->assertFalse($result['b']['success']);
        $this->assertStringContainsString('out of stock', strtolower($result['b']['error']));
        foreach ($result as $worker) {
            $this->assertSame([], $worker['sql_errors']);
            $this->assertArrayNotHasKey('exception', $worker);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertSame($hasVariants ? 5 : 0, $product->fresh()->stock_quantity);
        if ($other) {
            $this->assertSame(5, $other->fresh()->stock_quantity);
        }
        $this->assertSame(1, Order::query()->where('buyer_id', $buyers['a']->id)->count());
        $this->assertSame(0, Order::query()->where('buyer_id', $buyers['b']->id)->count());
        $this->assertTrue($tokens['a']->fresh()->used);
        $this->assertFalse($tokens['b']->fresh()->used);
        $this->assertModelMissing($lines['a']);
        $this->assertModelExists($lines['b']);
        $this->assertSame(1, OrderItem::query()->where('product_id', $product->id)->count());
        $ledger = InventoryTransaction::query()->where('variant_id', $variant->id)->where('reason', 'checkout')->sole();
        $this->assertSame(-1, $ledger->delta);
        $this->assertSame(1, $ledger->quantity_before);
        $this->assertSame(0, $ledger->quantity_after);
        $this->assertSame('cod', $ledger->sellerOrder->payment->method->value);
    }
}
