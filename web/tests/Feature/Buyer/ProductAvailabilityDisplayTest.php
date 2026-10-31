<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductAvailabilityDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_variant_page_does_not_present_total_stock_as_selected_availability(): void
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'stock_quantity' => 0, 'status' => 'active']);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Black', 'Green']], ['name' => 'Size', 'values' => ['S', 'M']]],
            collect(['Black', 'Green'])->flatMap(fn ($color) => collect(['S', 'M'])->map(fn ($size) => ['values' => [$color, $size], 'price' => 299, 'stock_quantity' => 225, 'is_available' => true]))->all(), true);
        $this->assertSame(900, $product->fresh()->stock_quantity);
        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('Please select a variation')
            ->assertDontSee('900 unit(s) available')
            ->assertViewHas('variantData', fn ($rows) => count($rows) === 4 && collect($rows)->every(fn ($row) => $row['stock'] === 225));
    }

    public function test_non_variant_product_keeps_its_normal_stock_display(): void
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'stock_quantity' => 17, 'status' => 'active']);
        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('17 unit(s) available')
            ->assertDontSee('Select color and size to see availability.');
    }
}
