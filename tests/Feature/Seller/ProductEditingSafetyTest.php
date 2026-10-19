<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Seller\Seller;
use App\Services\ProductPublishingService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductEditingSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(): array
    {
        $rows = [];
        foreach (['Black', 'White'] as $color) {
            foreach (['S', 'M'] as $size) {
                $rows[] = ['values' => [$color, $size], 'sku' => $color.'-'.$size,
                    'price' => 299, 'stock_quantity' => 10, 'is_available' => true];
            }
        }

        return ['name' => 'Oversized Cotton Shirt', 'price' => 299, 'stock_quantity' => 0, 'status' => 'active',
            'variants_exist' => true, 'options' => [
                ['name' => 'Color', 'values' => ['Black', 'White']],
                ['name' => 'Size', 'values' => ['S', 'M']],
            ], 'variants' => $rows];
    }

    public function test_a_stale_variant_edit_cannot_restore_stock_changed_after_opening_the_form(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $service = app(ProductPublishingService::class);
        $product = $service->save(new Product, $seller, $this->payload());
        $data = $this->payload();
        $data['inventory_revision'] = $service->inventoryRevision($product->load('variants'));
        $variant = $product->variants->first();
        app(StockService::class)->set($variant, 7);
        $product->syncVariantRollups();
        try {
            $service->save($product, $seller, $data);
            $this->fail('A stale stock snapshot must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_revision', $exception->errors());
        }
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertSame(37, $product->fresh()->stock_quantity);
    }

    public function test_single_sku_edits_also_reject_stale_stock(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $service = app(ProductPublishingService::class);
        $data = ['name' => 'Single shirt', 'price' => 299, 'stock_quantity' => 10, 'status' => 'active'];
        $product = $service->save(new Product, $seller, $data);
        $data['inventory_revision'] = $service->inventoryRevision($product->load('variants'));
        $variant = $product->variants->first();
        app(StockService::class)->set($variant, 8);
        $this->expectException(ValidationException::class);
        $service->save($product, $seller, $data);
    }

    public function test_removing_stock_history_combinations_is_rejected_without_changing_options_or_ids(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $service = app(ProductPublishingService::class);
        $product = $service->save(new Product, $seller, $this->payload());
        $ids = $product->variants()->orderBy('id')->pluck('id')->all();
        $data = $this->payload();
        $data['inventory_revision'] = $service->inventoryRevision($product->load('variants'));
        $data['options'][0]['values'] = ['Black'];
        $data['variants'] = array_slice($data['variants'], 0, 2);
        try {
            $service->save($product, $seller, $data);
            $this->fail('Referenced combinations must not be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }
        $this->assertSame($ids, $product->variants()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['Black', 'White'], $product->options()->first()->values()->pluck('value')->all());
        $this->assertDatabaseCount('inventory_transactions', 4);
    }

    public function test_disabling_a_referenced_combination_preserves_its_identity_and_stock(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $service = app(ProductPublishingService::class);
        $product = $service->save(new Product, $seller, $this->payload());
        $id = $product->variants()->first()->id;
        $data = $this->payload();
        $data['inventory_revision'] = $service->inventoryRevision($product->load('variants'));
        $data['variants'][0]['is_available'] = false;
        $service->save($product, $seller, $data);
        $variant = $product->variants()->findOrFail($id);
        $this->assertFalse($variant->is_available);
        $this->assertSame(10, $variant->stock_quantity);
        $this->assertSame(['Black', 'S'], $variant->optionValues->pluck('value')->all());
    }

    public function test_update_without_an_inventory_snapshot_is_rejected(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product),
            ['name' => 'Changed shirt', 'price' => 299, 'stock_quantity' => 10, 'status' => 'active'])
            ->assertSessionHasErrors('inventory_revision');
        $this->assertNotSame('Changed shirt', $product->fresh()->name);
    }

    public function test_buyer_price_range_uses_available_variant_prices(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload();
        $data['variants'][1]['price'] = 349;
        $data['variants'][2]['price'] = 199;
        $data['variants'][2]['is_available'] = false;
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $data);
        $expected = "\u{20B1}299.00\u{2013}\u{20B1}349.00";
        $this->assertSame($expected, $product->load('variants')->formattedPriceRange());
        $this->assertSame($expected, Product::query()->withBuyerCardData()->findOrFail($product->id)->formattedPriceRange());
    }
}
