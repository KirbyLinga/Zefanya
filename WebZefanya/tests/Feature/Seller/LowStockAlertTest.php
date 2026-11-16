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
use App\Notifications\Seller\LowStockAlert;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\StockService;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * SEL-08 (SEL-INV-008 / assumption A29): low-stock alerts are emitted exactly
 * once per threshold crossing, per product AND per variant, and re-arm only
 * after stock rises back above the threshold.
 */
class LowStockAlertTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function product(int $stock = 7, int $threshold = 5): array
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => $stock,
            'price' => '100.00',
            'low_stock_threshold' => $threshold,
        ]);

        return [$seller, $product, $product->variants()->sole()];
    }

    private function alerts(Seller $seller)
    {
        return $seller->notifications()->where('type', LowStockAlert::class)->get();
    }

    private function alertCount(Seller $seller): int
    {
        return $this->alerts($seller)->count();
    }

    /** Two option variants (Red / Blue) with independent stock. */
    private function variantProduct(int $stockPerVariant = 6, int $threshold = 5): array
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => $stockPerVariant * 2,
            'price' => '100.00',
            'low_stock_threshold' => $threshold,
        ]);
        app(VariantSyncService::class)->sync($product, [['name' => 'Color', 'values' => ['Red', 'Blue']]], [
            ['values' => ['Red'], 'price' => '100.00', 'stock_quantity' => $stockPerVariant, 'weight_grams' => 500],
            ['values' => ['Blue'], 'price' => '100.00', 'stock_quantity' => $stockPerVariant, 'weight_grams' => 500],
        ], true);

        [$red, $blue] = $product->variants()->orderBy('id')->get()->all();

        return [$seller, $product, $red, $blue];
    }

    private function checkout(Buyer $buyer, Seller $seller, Product $product, $variant): Order
    {
        $line = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $token = CheckoutToken::issue($buyer->id);
        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller]),
        ])->assertSessionMissing('error');

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_sel_inv_008_stock_six_does_not_alert(): void
    {
        [$seller, , $variant] = $this->product(7);

        app(StockService::class)->set($variant, 6);

        $this->assertSame(6, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $this->alertCount($seller));
    }

    public function test_sel_inv_008_crossing_six_to_five_alerts_exactly_once(): void
    {
        [$seller, , $variant] = $this->product(7);
        $stock = app(StockService::class);

        $stock->set($variant, 6);
        $stock->set($variant, 5);

        $this->assertSame(1, $this->alertCount($seller));
        $this->assertSame(5, $this->alerts($seller)->first()->data['quantity']);
        $this->assertSame($variant->id, $this->alerts($seller)->first()->data['variant_id']);
    }

    public function test_sel_inv_008_crossing_directly_below_five_alerts_once_with_the_actual_quantity(): void
    {
        [$seller, , $variant] = $this->product(7);
        $stock = app(StockService::class);
        $movement = $stock->set($variant, 3);
        $alert = $this->alerts($seller)->sole();
        $this->assertSame(3, $alert->data['quantity']);
        $this->assertSame($movement->id, $alert->data['inventory_transaction_id']);
        $stock->set($variant, 2);
        $this->assertSame(1, $this->alertCount($seller));
        $this->assertSame(2, $variant->fresh()->stock_quantity);
    }

    public function test_sel_inv_008_restocking_exactly_to_five_does_not_rearm_the_alert(): void
    {
        [$seller, , $variant] = $this->product(7);
        $stock = app(StockService::class);
        $stock->set($variant, 3);
        $this->assertSame(1, $this->alertCount($seller));
        $stock->set($variant, 5);
        $stock->set($variant, 4);
        $this->assertSame(1, $this->alertCount($seller));
        $stock->set($variant, 6);
        $stock->set($variant, 5);
        $this->assertSame(2, $this->alertCount($seller));
    }

    public function test_sel_inv_008_further_decreases_to_zero_do_not_duplicate(): void
    {
        [$seller, , $variant] = $this->product(7);
        $stock = app(StockService::class);

        $stock->set($variant, 6);
        $stock->set($variant, 5);
        $this->assertSame(1, $this->alertCount($seller));

        foreach ([4, 3, 2, 1, 0] as $quantity) {
            $stock->set($variant, $quantity);
            $this->assertSame($quantity, $variant->fresh()->stock_quantity);
            $this->assertSame(1, $this->alertCount($seller), "Stock {$quantity} must not duplicate the alert.");
        }
    }

    public function test_sel_inv_008_restock_above_threshold_rearms_the_alert(): void
    {
        [$seller, , $variant] = $this->product(6);
        $stock = app(StockService::class);

        $stock->set($variant, 5);
        $this->assertSame(1, $this->alertCount($seller));

        $stock->set($variant, 6);
        $this->assertSame(1, $this->alertCount($seller), 'Restocking must not emit an alert.');

        $stock->set($variant, 5);
        $this->assertSame(2, $this->alertCount($seller), 'The later crossing must alert again.');
    }

    public function test_sel_inv_008_repeated_writes_and_noop_updates_do_not_duplicate(): void
    {
        [$seller, , $variant] = $this->product(6);
        $stock = app(StockService::class);

        $stock->set($variant, 5);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($stock->set($variant, 5), 'Unchanged quantities must not write a movement.');
        }

        $this->assertSame(1, $this->alertCount($seller));
        $this->assertSame(1, InventoryTransaction::query()->where('variant_id', $variant->id)->where('delta', -1)->count());
    }

    public function test_sel_inv_008_alert_tracking_is_independent_per_product(): void
    {
        [$sellerA, $productA, $variantA] = $this->product(6);
        $sellerB = Seller::factory()->create();
        $productB = Product::factory()->create(['seller_id' => $sellerB->id, 'stock_quantity' => 6, 'price' => '100.00', 'low_stock_threshold' => 5]);
        $variantB = $productB->variants()->sole();

        app(StockService::class)->set($variantA, 5);
        app(StockService::class)->set($variantB, 5);

        $this->assertSame(1, $this->alertCount($sellerA));
        $this->assertSame(1, $this->alertCount($sellerB));
        $this->assertSame($productA->id, $this->alerts($sellerA)->first()->data['product_id']);
        $this->assertSame($productB->id, $this->alerts($sellerB)->first()->data['product_id']);
    }

    public function test_sel_inv_008_alert_tracking_is_independent_per_variant(): void
    {
        [$seller, $product, $red, $blue] = $this->variantProduct(6);
        $stock = app(StockService::class);

        // Product rollup stays well above the threshold (11 > 5); only Red crosses.
        $stock->set($red, 5);
        $this->assertSame(11, (int) $product->variants()->sum('stock_quantity'));
        $this->assertSame(1, $this->alertCount($seller), 'A variant crossing must alert even when the product rollup is high.');
        $this->assertSame($red->id, $this->alerts($seller)->first()->data['variant_id']);

        $stock->set($blue, 5);
        $this->assertSame(2, $this->alertCount($seller));
        $this->assertEqualsCanonicalizing(
            [$red->id, $blue->id],
            $this->alerts($seller)->pluck('data')->pluck('variant_id')->all(),
        );

        // Red dropping further is not a new crossing for Red.
        $stock->set($red, 1);
        $this->assertSame(2, $this->alertCount($seller));
    }

    public function test_sel_inv_008_notification_identifies_the_affected_variant(): void
    {
        [$seller, , $red] = $this->variantProduct(6);

        app(StockService::class)->set($red, 5);

        $data = $this->alerts($seller)->sole()->data;
        $this->assertSame($red->id, $data['variant_id']);
        $this->assertSame('Red', $data['variant_label']);
        $this->assertStringContainsString('(Red)', $data['message']);
        $this->assertStringContainsString('5 units left', $data['message']);
    }

    public function test_sel_inv_008_default_variant_message_stays_product_scoped(): void
    {
        [$seller, $product, $variant] = $this->product(6);

        app(StockService::class)->set($variant, 5);

        $data = $this->alerts($seller)->sole()->data;
        $this->assertSame('Default', $data['variant_label']);
        $this->assertSame($product->name.' has 5 units left.', $data['message']);
    }

    public function test_sel_inv_008_cod_sales_crossing_and_reduction_alert_once(): void
    {
        [$seller, $product, $variant] = $this->product(7);
        $buyer = Buyer::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        $this->checkout($buyer, $seller, $product, $variant);
        $this->assertSame(6, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $this->alertCount($seller));

        $crossing = $this->checkout($buyer, $seller, $product, $variant);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $this->alertCount($seller));

        // Cancelling the crossing sale restores stock above the threshold:
        // re-arm without a notification, then the next sale alerts once more.
        app(SellerOrderStatusService::class)->transition(
            $crossing->sellerOrders()->sole(), OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $buyer->id, source: 'web',
        );
        $this->assertSame(6, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $this->alertCount($seller));

        $this->checkout($buyer, $seller, $product, $variant);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(2, $this->alertCount($seller));

        $this->checkout($buyer, $seller, $product, $variant);
        $this->assertSame(4, $variant->fresh()->stock_quantity);
        $this->assertSame(2, $this->alertCount($seller));
    }

    public function test_sel_inv_008_cancel_restoration_to_above_threshold_rearms(): void
    {
        [$seller, $product, $variant] = $this->product(6);
        $buyer = Buyer::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        $order = $this->checkout($buyer, $seller, $product, $variant);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $this->alertCount($seller));

        // 5 -> 6 restores above the threshold without emitting a notification.
        app(SellerOrderStatusService::class)->transition(
            $order->sellerOrders()->sole(), OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $buyer->id, source: 'web',
        );
        $this->assertSame(6, $variant->fresh()->stock_quantity);
        $this->assertSame(1, $this->alertCount($seller));

        // 6 -> 5 is a fresh crossing.
        app(StockService::class)->set($variant, 5);
        $this->assertSame(2, $this->alertCount($seller));
    }

    public function test_sel_inv_008_dashboard_reflects_variant_rollup_and_product_threshold(): void
    {
        [$seller, $low, $lowVariant] = $this->product(6, 5);
        app(StockService::class)->set($lowVariant, 4);

        $healthy = Product::factory()->create([
            'seller_id' => $seller->id, 'name' => 'HEALTHY-STOCK-PRODUCT',
            'stock_quantity' => 40, 'price' => '100.00', 'low_stock_threshold' => 5,
        ]);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee($low->name)
            ->assertDontSee($healthy->name)
            ->assertSee('4 left');
    }

    public function test_sel_inv_008_products_index_low_stock_filter_lists_only_low_products(): void
    {
        [$seller, $low, $lowVariant] = $this->product(6, 5);
        $low->forceFill(['name' => 'LOWSTOCK-FILTER-ALPHA'])->save();
        app(StockService::class)->set($lowVariant, 5);

        Product::factory()->create([
            'seller_id' => $seller->id, 'name' => 'HEALTHY-FILTER-OMEGA',
            'stock_quantity' => 40, 'price' => '100.00', 'low_stock_threshold' => 5,
        ]);

        $this->actingAs($seller, 'seller')->get(route('seller.products.index', ['stock' => 'low']))
            ->assertOk()
            ->assertSee('LOWSTOCK-FILTER-ALPHA')
            ->assertDontSee('HEALTHY-FILTER-OMEGA');

        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('LOWSTOCK-FILTER-ALPHA')
            ->assertSee('HEALTHY-FILTER-OMEGA');
    }

    public function test_sel_inv_008_products_index_stock_pill_uses_product_threshold(): void
    {
        [$seller, $product] = $this->product(4, 3);
        $product->forceFill(['name' => 'THRESHOLD-PILL-PRODUCT'])->save();

        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('4 in stock')
            ->assertDontSee('4 left (Low)');
    }
}
