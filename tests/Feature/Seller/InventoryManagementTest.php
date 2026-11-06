<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Seller\LowStockAlert;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\StockService;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase, ValidProductPublication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function fixture(int $stock = 20, int $quantity = 2): array
    {
        $seller = Seller::factory()->create();
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => $stock, 'price' => '100.00', 'low_stock_threshold' => 5]);
        $variant = $product->variants()->sole();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $quantity]);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        return [$seller, $buyer, $product, $variant, $line];
    }

    private function checkout(Buyer $buyer, Seller $seller, CartItem $line): Order
    {
        $token = CheckoutToken::issue($buyer->id);
        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller]),
        ])->assertSessionMissing('error');

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_sel_inv_001_and_003_cod_deducts_immediately_and_cancellation_restores_once(): void
    {
        [$seller, $buyer, $product, $variant, $line] = $this->fixture();
        $order = $this->checkout($buyer, $seller, $line);
        $this->assertSame(18, $variant->fresh()->stock_quantity);
        $this->assertSame(18, $product->fresh()->stock_quantity);
        $slice = $order->sellerOrders()->sole();
        $this->assertSame('cod', $slice->payment->method->value);
        $service = app(SellerOrderStatusService::class);
        $service->transition($slice, OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $buyer->id, source: 'web');
        $service->transition($slice->fresh(), OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $buyer->id, source: 'web');
        $this->assertSame(20, $variant->fresh()->stock_quantity);
        $this->assertSame(20, $product->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'cancel')->count());
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'checkout')->count());
    }

    public function test_sel_inv_005_variant_stock_is_independent(): void
    {
        [$seller, $buyer, $product, $default, $line] = $this->fixture();
        $line->delete();
        app(VariantSyncService::class)->sync($product, [['name' => 'Size', 'values' => ['M', 'L']]], [
            ['values' => ['M'], 'price' => '100.00', 'stock_quantity' => 8, 'weight_grams' => 500],
            ['values' => ['L'], 'price' => '100.00', 'stock_quantity' => 5, 'weight_grams' => 500],
        ], true);
        [$medium, $large] = $product->variants()->orderBy('id')->get()->all();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $medium->id, 'quantity' => 1]);
        $this->checkout($buyer, $seller, $line);
        $this->assertSame(7, $medium->fresh()->stock_quantity);
        $this->assertSame(5, $large->fresh()->stock_quantity);
        $this->assertSame(12, $product->fresh()->stock_quantity);
    }

    public function test_sel_inv_007_last_unit_is_sold_out_and_server_rejects_purchase(): void
    {
        [$seller, $buyer, $product, $variant, $line] = $this->fixture(1, 1);
        $this->checkout($buyer, $seller, $line);
        $this->get(route('buyer.products.show', $product))->assertSee('Sold Out');
        $this->get(route('buyer.search.index', ['q' => $product->name]))->assertSee('Sold Out')
            ->assertViewHas('products', fn ($products) => $products->contains('id', $product->id));
        $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
        $this->postJson(route('buyer.checkout.buy-now'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))->assertSee('Out of Stock');
        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_sel_inv_008_low_stock_alert_is_once_per_threshold_crossing(): void
    {
        [$seller, $buyer, $product, $variant] = $this->fixture(7, 1);
        $stock = app(StockService::class);
        $stock->set($variant, 6);
        $this->assertSame(0, $seller->notifications()->where('type', LowStockAlert::class)->count());
        $stock->set($variant, 5);
        $this->assertSame(1, $seller->notifications()->where('type', LowStockAlert::class)->count());
        $stock->set($variant, 4);
        $this->assertSame(1, $seller->notifications()->where('type', LowStockAlert::class)->count());
        $stock->set($variant, 7);
        $stock->set($variant, 5);
        $this->assertSame(2, $seller->notifications()->where('type', LowStockAlert::class)->count());
        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->assertSee('Low stock')->assertSee('5 units left');
    }

    public function test_sel_inv_008_actual_cod_sales_trigger_one_low_stock_alert_at_five(): void
    {
        [$seller, $buyer, $product, $variant, $line] = $this->fixture(7, 1);
        foreach ([6, 5, 4] as $quantityAfter) {
            if ($quantityAfter !== 6) {
                $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
            }
            $this->checkout($buyer, $seller, $line);
            $this->assertSame($quantityAfter, $variant->fresh()->stock_quantity);
            $this->assertSame($quantityAfter < 6 ? 1 : 0, $seller->notifications()->where('type', LowStockAlert::class)->count());
        }
    }

    public function test_low_stock_notification_rolls_back_with_inventory(): void
    {
        [$seller, $buyer, $product, $variant] = $this->fixture(7, 1);
        DB::beginTransaction();
        app(StockService::class)->set($variant, 5);
        $this->assertSame(1, $seller->notifications()->where('type', LowStockAlert::class)->count());
        DB::rollBack();
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $seller->notifications()->where('type', LowStockAlert::class)->count());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public static function stockValues(): array
    {
        return [[-1, false], [0, true], [99999, true], [100000, false], [2.5, false], ['abc', false]];
    }

    #[DataProvider('stockValues')]
    public function test_sel_inv_009_stock_service_rejects_invalid_quantities_without_clamping(mixed $quantity, bool $valid): void
    {
        [$seller, $buyer, $product, $variant] = $this->fixture();
        if (! $valid) {
            try {
                app(StockService::class)->set($variant, $quantity);
                $this->fail('Invalid stock must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('stock_quantity', $exception->errors());
            }
            $this->assertSame(20, $variant->fresh()->stock_quantity);
            $this->assertDatabaseCount('inventory_transactions', 0);
        } else {
            app(StockService::class)->set($variant, $quantity);
            $this->assertSame($quantity, $variant->fresh()->stock_quantity);
            $this->assertDatabaseCount('inventory_transactions', 1);
        }
    }

    #[DataProvider('stockValues')]
    public function test_sel_inv_009_manual_product_http_stock_boundaries(mixed $quantity, bool $valid): void
    {
        [$seller, $buyer, $product, $variant] = $this->fixture();
        $data = $this->publicationDefaults() + [
            'name' => $product->name, 'price' => $product->price, 'weight' => $product->weight_grams, 'weight_unit' => 'g',
            'stock_quantity' => $quantity, 'status' => 'active',
            'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->load('variants')),
        ];
        $response = $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), $data);
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($quantity, $variant->fresh()->stock_quantity);
            $this->assertSame($quantity, $product->fresh()->stock_quantity);
        } else {
            $response->assertSessionHasErrors('stock_quantity');
            $this->assertSame(20, $variant->fresh()->stock_quantity);
            $this->assertDatabaseCount('inventory_transactions', 0);
        }
    }

    public function test_direct_variant_sync_does_not_truncate_a_fraction_or_create_partial_inventory(): void
    {
        [$seller, $buyer, $product, $variant, $line] = $this->fixture();
        $line->delete();
        try {
            app(VariantSyncService::class)->sync($product, [['name' => 'Size', 'values' => ['M']]], [
                ['values' => ['M'], 'price' => 100, 'stock_quantity' => 2.5],
            ], true);
            $this->fail('A fractional quantity must not be cast to an integer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variants.0.stock_quantity', $exception->errors());
        }
        $this->assertSame(20, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_automatic_return_restoration_can_exceed_the_seller_entry_limit_but_never_runs_twice(): void
    {
        [$seller, $buyer, $product, $variant] = $this->fixture(99999, 1);
        $order = SellerOrder::factory()->create(['seller_id' => $seller->id]);
        $service = app(StockService::class);
        $service->set($variant, 100001, 'return', $order->id);
        $this->assertNull($service->set($variant, 100003, 'return', $order->id));
        $this->assertSame(100001, $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
    }

    public function test_recording_opening_balances_twice_does_not_duplicate_ledger_entries(): void
    {
        [$seller, $buyer, $product] = $this->fixture();
        app(StockService::class)->recordOpeningBalances($product);
        app(StockService::class)->recordOpeningBalances($product);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', StockService::REASON_SELLER_INITIAL)->count());
    }

    public function test_sel_inv_010_reduced_stock_shows_the_current_limit_and_blocks_checkout_until_corrected(): void
    {
        [$seller, $buyer, $product, $variant, $line] = $this->fixture(7, 5);
        app(StockService::class)->set($variant, 3);
        $product->syncVariantRollups();
        $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'))->assertSee('Only 3 left');
        $this->get(route('buyer.checkout.index', ['items' => [$line->id]]))->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'Only 3 left'));
        $token = CheckoutToken::issue($buyer->id);
        $this->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), ['checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller])])->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->patchJson(route('buyer.cart.update', $line), ['quantity' => 3])->assertOk();
        $this->checkout($buyer, $seller, $line->fresh());
        $this->assertSame(0, $variant->fresh()->stock_quantity);
    }

    public static function variantStockValues(): array
    {
        $cases = [];
        foreach (self::stockValues() as [$quantity, $valid]) {
            $cases[] = [$quantity, $valid, false];
            $cases[] = [$quantity, $valid, true];
        }

        return $cases;
    }

    #[DataProvider('variantStockValues')]
    public function test_individual_variant_limits_apply_to_creation_and_editing(mixed $quantity, bool $valid, bool $editing): void
    {
        $seller = Seller::factory()->create();
        $rows = [
            ['values' => ['M'], 'price' => 100, 'stock_quantity' => 8, 'weight' => 500, 'weight_unit' => 'g', 'weight_grams' => 500],
            ['values' => ['L'], 'price' => 100, 'stock_quantity' => 5, 'weight' => 500, 'weight_unit' => 'g', 'weight_grams' => 500],
        ];
        $options = [['name' => 'Size', 'values' => ['M', 'L']]];
        $data = $this->publicationDefaults() + ['name' => 'Stock boundary shirt', 'price' => 100, 'stock_quantity' => 0, 'status' => 'active', 'variants_exist' => 1, 'options' => $options, 'variants' => $rows];
        $product = null;
        if ($editing) {
            $product = Product::factory()->create(['seller_id' => $seller->id]);
            app(VariantSyncService::class)->sync($product, $options, $rows, true);
            $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        }
        $data['variants'][0]['stock_quantity'] = $quantity;
        $this->actingAs($seller, 'seller');
        $response = $editing ? $this->put(route('seller.products.update', $product), $data) : $this->post(route('seller.products.store'), $data);
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $product ??= Product::query()->sole();
            $this->assertSame([$quantity, 5], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());
        } else {
            $response->assertSessionHasErrors('variants.0.stock_quantity');
            if ($editing) {
                $this->assertSame([8, 5], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());
                $this->assertDatabaseCount('inventory_transactions', 2);
            } else {
                $this->assertDatabaseCount('products', 0);
                $this->assertDatabaseCount('inventory_transactions', 0);
            }
        }
    }
}
