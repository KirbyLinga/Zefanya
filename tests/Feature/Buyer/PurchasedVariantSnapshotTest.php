<?php

namespace Tests\Feature\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class PurchasedVariantSnapshotTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function shirt(?Seller $seller = null, string $name = 'Classic Cotton Shirt'): Product
    {
        $seller ??= Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'name' => $name, 'price' => '100.25', 'stock_quantity' => 20]);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Black', 'White']], ['name' => 'Size', 'values' => ['Large', 'Small']]],
            [
                ['values' => ['Black', 'Large'], 'price' => '100.25', 'stock_quantity' => 5, 'is_available' => true],
                ['values' => ['Black', 'Small'], 'price' => '90.00', 'stock_quantity' => 5, 'is_available' => true],
                ['values' => ['White', 'Large'], 'price' => '110.00', 'stock_quantity' => 5, 'is_available' => true],
                ['values' => ['White', 'Small'], 'price' => '120.50', 'stock_quantity' => 5, 'is_available' => true],
            ], true);

        return $product;
    }

    private function variant(Product $product, string $color = 'Black', string $size = 'Large'): ProductVariant
    {
        return $product->variants()->with('optionValues')->get()->firstOrFail(
            fn ($variant) => $variant->optionValues->pluck('value')->all() === [$color, $size],
        );
    }

    private function expectedOptions(string $color = 'Black', string $size = 'Large'): array
    {
        return [['name' => 'Color', 'value' => $color], ['name' => 'Size', 'value' => $size]];
    }

    private function line(Buyer $buyer, Product $product, ProductVariant $variant, int $quantity = 2): CartItem
    {
        return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $quantity]);
    }

    private function purchase(Buyer $buyer, array $lines): Order
    {
        $sellers = collect($lines)
            ->map(fn (CartItem $line) => $line->product->seller)
            ->unique('id')
            ->values();
        $this->bootstrapCheckoutCarriers($buyer, $sellers);

        $this->actingAs($buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => array_map(fn ($line) => $line->id, $lines)]);
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            ...$this->checkoutCarrierPayload($sellers),
        ])
            ->assertSessionMissing('error')->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function visible(Order $order, array $labels = ['Color: Black / Size: Large']): void
    {
        foreach ([route('buyer.orders.index'), route('buyer.orders.show', $order), route('buyer.checkout.success', $order)] as $url) {
            $response = $this->get($url)->assertOk();
            foreach ($labels as $label) {
                $response->assertSee($label);
            }
        }
    }

    public function test_checkout_saves_selected_options_with_unchanged_financial_and_inventory_snapshots(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $variant = $this->variant($product);
        $order = $this->purchase($buyer, [$this->line($buyer, $product, $variant)]);

        $item = OrderItem::query()->sole();
        $this->assertSame($this->expectedOptions(), $item->variant_options);
        $this->assertSame('Classic Cotton Shirt', $item->product_name);
        $this->assertSame($variant->id, $item->variant_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(10025, $item->price_minor);
        $this->assertSame(20050, $item->lineTotalMinor());
        $this->assertSame(20050, $order->subtotal_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(24950, $order->total_minor);
        $this->assertSame(20050, $order->sellerOrders()->sole()->subtotal_minor);
        $this->assertSame(24950, Payment::query()->sole()->amount_minor);
        $this->assertSame('cod', Payment::query()->sole()->method->value);
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(18, $product->fresh()->stock_quantity);
        $ledger = InventoryTransaction::query()->where('reason', 'checkout')->sole();
        $this->assertSame([5, 3, -2], [$ledger->quantity_before, $ledger->quantity_after, $ledger->delta]);
        $this->assertDatabaseCount('cart_items', 0);
        $this->visible($order, ['Classic Cotton Shirt', 'Color: Black / Size: Large']);
    }

    public static function laterCatalogChanges(): array
    {
        return [['color'], ['size'], ['names_and_price'], ['disable'], ['delete_variant'], ['delete_product']];
    }

    #[DataProvider('laterCatalogChanges')]
    public function test_later_catalog_changes_do_not_rewrite_saved_purchase_details(string $change): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $variant = $this->variant($product);
        $order = $this->purchase($buyer, [$this->line($buyer, $product, $variant)]);
        $item = OrderItem::query()->sole();

        if ($change === 'color' || $change === 'size') {
            $axis = $change === 'color' ? 'Color' : 'Size';
            $value = $variant->optionValues()->whereHas('option', fn ($q) => $q->where('name', $axis))->sole();
            $value->update(['value' => $change === 'color' ? 'Navy Blue' : 'Medium']);
        } elseif ($change === 'names_and_price') {
            $product->update(['name' => 'Renamed current catalog item']);
            $product->options()->update(['name' => 'Renamed option']);
            $variant->update(['price' => '999.00']);
        } elseif ($change === 'disable') {
            $variant->update(['is_available' => false]);
        } elseif ($change === 'delete_variant') {
            // Database-supported hard deletion; seller UI retirement restrictions are unchanged.
            $variant->delete();
            $product->syncVariantRollups();
            $this->assertNull($item->fresh()->variant_id);
        } else {
            $product->delete();
        }

        $item->refresh();
        $this->assertSame($this->expectedOptions(), $item->variant_options);
        $this->assertSame('Classic Cotton Shirt', $item->product_name);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(10025, $item->price_minor);
        $this->assertSame(20050, $item->lineTotalMinor());
        $this->assertSame(24950, $order->fresh()->total_minor);
        $this->assertSame(24950, Payment::query()->sole()->amount_minor);
        $this->visible($order, ['Classic Cotton Shirt', 'Color: Black / Size: Large']);
    }

    public function test_multiple_variants_of_the_same_product_keep_distinct_snapshots(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $black = $this->variant($product);
        $white = $this->variant($product, 'White', 'Small');
        $order = $this->purchase($buyer, [$this->line($buyer, $product, $black), $this->line($buyer, $product, $white, 1)]);

        $items = OrderItem::query()->get()->keyBy('variant_id');
        $this->assertSame($this->expectedOptions(), $items[$black->id]->variant_options);
        $this->assertSame($this->expectedOptions('White', 'Small'), $items[$white->id]->variant_options);
        $this->assertSame(37000, $order->total_minor);
        $this->assertSame([3, 4], [$black->fresh()->stock_quantity, $white->fresh()->stock_quantity]);
        $this->visible($order, ['Color: Black / Size: Large', 'Color: White / Size: Small']);
    }

    public function test_multi_seller_checkout_preserves_each_sellers_purchased_options(): void
    {
        $buyer = Buyer::factory()->create();
        $first = $this->shirt();
        $second = $this->shirt(name: 'Second Shop Shirt');
        $a = $this->variant($first);
        $b = $this->variant($second, 'White', 'Small');
        $order = $this->purchase($buyer, [$this->line($buyer, $first, $a), $this->line($buyer, $second, $b, 3)]);

        $this->assertSame(2, $order->sellerOrders()->count());
        $items = OrderItem::query()->get()->keyBy('product_id');
        $this->assertSame($this->expectedOptions(), $items[$first->id]->variant_options);
        $this->assertSame($this->expectedOptions('White', 'Small'), $items[$second->id]->variant_options);
        $this->assertSame(66000, $order->total_minor);
        $this->assertSame(66000, (int) Payment::query()->sum('amount_minor'));
        $this->assertSame([3, 2], [$a->fresh()->stock_quantity, $b->fresh()->stock_quantity]);
        $this->visible($order, ['Classic Cotton Shirt', 'Second Shop Shirt', 'Color: Black / Size: Large', 'Color: White / Size: Small']);
    }

    public function test_optionless_product_has_no_invented_default_color_or_size(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['name' => 'Plain Mug', 'price' => '12.50', 'stock_quantity' => 5]);
        $order = $this->purchase($buyer, [$this->line($buyer, $product, $product->variants()->sole(), 1)]);

        $item = OrderItem::query()->sole();
        $this->assertSame([], $item->variant_options);
        $this->assertSame('', $item->variantDescription());
        $this->assertSame(6150, $order->total_minor);
        $this->visible($order, ['Plain Mug']);
        foreach ([route('buyer.orders.index'), route('buyer.orders.show', $order)] as $url) {
            $this->get($url)->assertDontSee('Color:')->assertDontSee('Size:');
        }
    }

    public function test_historical_missing_snapshots_never_infer_options_from_a_live_variant(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $variant = $this->variant($product);
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id, 'seller_id' => $product->seller_id]);
        $item = OrderItem::factory()->create(['seller_order_id' => $sellerOrder->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'product_name' => 'Legacy purchased shirt']);

        $this->actingAs($buyer, 'buyer');
        $this->assertNull($item->fresh()->variant_options);
        $this->assertSame('', $item->fresh()->variantDescription());
        $this->visible($order, ['Legacy purchased shirt']);
        foreach ([route('buyer.orders.index'), route('buyer.orders.show', $order)] as $url) {
            $this->get($url)->assertDontSee('Color: Black')->assertDontSee('Size: Large');
        }
    }

    public function test_historical_item_with_no_product_or_variant_still_renders(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id]);
        $item = OrderItem::factory()->create(['seller_order_id' => $sellerOrder->id, 'product_id' => null, 'variant_id' => null, 'product_name' => 'Removed legacy item', 'price_minor' => 2500]);

        $this->actingAs($buyer, 'buyer');
        $this->assertNull($item->fresh()->variant_options);
        $this->visible($order, ['Removed legacy item']);
    }

    public function test_checkout_captures_current_locked_options_instead_of_stale_preloaded_values(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $variant = $this->variant($product);
        $line = $this->line($buyer, $product, $variant);
        $changed = false;
        $oldColor = null;
        ProductVariant::retrieved(function (ProductVariant $row) use ($variant, &$changed, &$oldColor): void {
            if (! $changed && $row->id === $variant->id) {
                $changed = true;
                $row->load('optionValues.option');
                $color = $row->optionValues->first(fn ($value) => $value->option->name === 'Color');
                $oldColor = $color->value;
                DB::table('product_option_values')->where('id', $color->id)->update(['value' => 'Navy Blue']);
            }
        });

        $order = $this->purchase($buyer, [$line]);

        $this->assertTrue($changed);
        $this->assertSame('Black', $oldColor);
        $this->assertSame($this->expectedOptions('Navy Blue'), OrderItem::query()->sole()->variant_options);
        $this->assertSame(24950, $order->total_minor);
        $this->visible($order, ['Color: Navy Blue / Size: Large']);
    }

    public function test_other_named_options_are_preserved_without_fake_color_or_size(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['name' => 'Cotton Tote', 'price' => '25.00', 'stock_quantity' => 5]);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Material', 'values' => ['Cotton']]],
            [['values' => ['Cotton'], 'price' => '25.00', 'stock_quantity' => 5, 'is_available' => true]], true);
        $order = $this->purchase($buyer, [$this->line($buyer, $product, $product->variants()->sole(), 1)]);

        $this->assertSame([['name' => 'Material', 'value' => 'Cotton']], OrderItem::query()->sole()->variant_options);
        $this->visible($order, ['Material: Cotton']);
        $this->get(route('buyer.orders.show', $order))->assertDontSee('Color:')->assertDontSee('Size:');
    }

    public function test_additive_migration_retains_legacy_data_without_backfilling_live_options(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->shirt();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id]);
        $item = OrderItem::factory()->create(['seller_order_id' => $sellerOrder->id, 'product_id' => $product->id,
            'variant_id' => $this->variant($product)->id, 'product_name' => 'Original legacy snapshot',
            'quantity' => 2, 'price_minor' => 4321]);
        $migration = require database_path('migrations/2026_10_18_042755_add_variant_options_to_order_items_table.php');

        // Reproduce an existing pre-T06 row in the disposable SQLite schema.
        $migration->down();
        $before = (array) DB::table('order_items')->where('id', $item->id)->first();
        $migration->up();
        $after = (array) DB::table('order_items')->where('id', $item->id)->first();

        $this->assertNull($after['variant_options']);
        unset($after['variant_options']);
        $this->assertSame($before, $after);
        $this->assertSame('', $item->fresh()->variantDescription());
        $this->actingAs($buyer, 'buyer');
        $this->visible($order, ['Original legacy snapshot']);
    }
}
