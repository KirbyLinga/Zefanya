<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Services\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerStockLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create();
    }

    private function updatePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
            'name' => $product->name,
            'price' => (string) $product->price,
            'stock_quantity' => $product->stock_quantity,
            'status' => 'active',
        ], $overrides);
    }

    public function test_creating_a_product_logs_its_opening_stock(): void
    {
        Storage::fake('public');

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.products.store'), [
                'name' => 'Ledger Lamp',
                'price' => '250.00',
                'stock_quantity' => 12,
                'status' => 'active',
                'images' => [UploadedFile::fake()->image('lamp.jpg')],
            ])
            ->assertRedirect(route('seller.products.index'));

        $product = Product::query()->where('name', 'Ledger Lamp')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertDatabaseHas('inventory_transactions', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'seller_order_id' => null,
            'quantity_before' => 0,
            'quantity_after' => 12,
            'delta' => 12,
            'reason' => 'seller_initial',
        ]);
        $this->assertSame(1, InventoryTransaction::query()->where('product_id', $product->id)->count());
    }

    public function test_editing_stock_logs_an_adjustment_and_keeps_rollups_in_sync(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 10]);

        $this->actingAs($this->seller, 'seller')
            ->put(route('seller.products.update', $product), $this->updatePayload($product, ['stock_quantity' => 4]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $variant = $product->variants()->firstOrFail();

        $this->assertDatabaseHas('inventory_transactions', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_before' => 10,
            'quantity_after' => 4,
            'delta' => -6,
            'reason' => 'seller_adjustment',
        ]);
        $this->assertSame(4, $variant->fresh()->stock_quantity);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_saving_without_changing_stock_writes_no_ledger_row(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 10]);

        $this->actingAs($this->seller, 'seller')
            ->put(route('seller.products.update', $product), $this->updatePayload($product, ['name' => 'Renamed Only']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, InventoryTransaction::query()->where('product_id', $product->id)->count());
    }

    public function test_new_option_variants_log_their_opening_stock(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'stock_quantity' => 0]);

        $this->actingAs($this->seller, 'seller')
            ->put(route('seller.products.update', $product), $this->updatePayload($product, [
                'variants_exist' => 1,
                'options' => [['name' => 'Size', 'values' => 'S, M']],
                'variants' => [
                    ['values' => 'S', 'price' => '100.00', 'stock_quantity' => 5, 'is_available' => 1],
                    ['values' => 'M', 'price' => '100.00', 'stock_quantity' => 7, 'is_available' => 1],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $rows = InventoryTransaction::query()
            ->where('product_id', $product->id)
            ->where('reason', 'seller_initial')
            ->orderBy('quantity_after')
            ->get();

        $this->assertSame([5, 7], $rows->pluck('quantity_after')->all());
        $this->assertSame([5, 7], $rows->pluck('delta')->all());
        $this->assertSame(12, $product->fresh()->stock_quantity);
    }
}
