<?php

namespace Tests\MySql;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Notifications\Seller\LowStockAlert;
use App\Services\Seller\VariantSyncService;
use Tests\TestCase;

/**
 * SEL-08 item 7: concurrent stock movements must not duplicate a low-stock
 * alert for the same crossing, and each variant's crossing must stay
 * independent when two variants move at the same time.
 */
class LowStockAlertConcurrencyTest extends TestCase
{
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
    }

    public function test_sel_inv_008_simultaneous_variant_crossings_alert_once_each(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => 12,
            'price' => '100.00',
            'low_stock_threshold' => 5,
        ]);
        app(VariantSyncService::class)->sync($product, [['name' => 'Color', 'values' => ['Red', 'Blue']]], [
            ['values' => ['Red'], 'price' => '100.00', 'stock_quantity' => 6, 'weight_grams' => 500],
            ['values' => ['Blue'], 'price' => '100.00', 'stock_quantity' => 6, 'weight_grams' => 500],
        ], true);
        [$red, $blue] = $product->variants()->orderBy('id')->get()->all();

        $result = Race::run(
            ['action' => 'stock_set', 'variant' => $red->id, 'quantity' => 5, 'hold' => 'variant'],
            ['action' => 'stock_set', 'variant' => $blue->id, 'quantity' => 5, 'hold' => 'variant'],
            bothHeld: true,
        );

        foreach ($result as $worker) {
            $this->assertTrue($worker['success']);
            $this->assertSame([], $worker['sql_errors']);
            $this->assertArrayNotHasKey('exception', $worker);
        }

        $this->assertSame(5, $red->fresh()->stock_quantity);
        $this->assertSame(5, $blue->fresh()->stock_quantity);

        $alerts = $seller->notifications()->where('type', LowStockAlert::class)->get();
        $this->assertCount(2, $alerts, 'Each variant crossing alerts exactly once, with no duplicate.');
        $this->assertEqualsCanonicalizing(
            [$red->id, $blue->id],
            $alerts->pluck('data')->pluck('variant_id')->all(),
        );

        // Repeating the same absolute quantities writes no movement and no alert.
        $repeat = Race::run(
            ['action' => 'stock_set', 'variant' => $red->id, 'quantity' => 5, 'hold' => 'variant'],
            ['action' => 'stock_set', 'variant' => $blue->id, 'quantity' => 5, 'hold' => 'variant'],
            bothHeld: true,
        );
        foreach ($repeat as $worker) {
            $this->assertTrue($worker['success']);
        }
        $this->assertCount(2, $seller->notifications()->where('type', LowStockAlert::class)->get());
    }

    public function test_sel_inv_008_simultaneous_same_variant_crossing_alerts_once(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => 7,
            'price' => '100.00',
            'low_stock_threshold' => 5,
        ]);
        $variant = $product->variants()->sole();

        // Both workers target the same variant row: one crosses 7 -> 5 (or
        // 7 -> 4), the other continues from the committed value. Exactly one
        // alert may be emitted for this single crossing window.
        $result = Race::run(
            ['action' => 'stock_set', 'variant' => $variant->id, 'quantity' => 5, 'hold' => 'variant'],
            ['action' => 'stock_set', 'variant' => $variant->id, 'quantity' => 4, 'watch' => 'variant'],
        );

        foreach ($result as $worker) {
            $this->assertTrue($worker['success']);
            $this->assertSame([], $worker['sql_errors']);
            $this->assertArrayNotHasKey('exception', $worker);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);

        $this->assertContains($variant->fresh()->stock_quantity, [4, 5]);
        $this->assertCount(
            1,
            $seller->notifications()->where('type', LowStockAlert::class)->get(),
            'A single threshold crossing must never produce a duplicate alert.',
        );
    }
}
