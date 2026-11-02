<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Database\Seeders\EdGamingCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EdGamingCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_forty_purchasable_products_with_real_photos_once(): void
    {
        Storage::fake('public');
        Category::query()->firstOrCreate(['label' => 'Electronics and Gadgets'], ['icon' => 'smartphone']);
        $seller = Seller::factory()->create(['business_name' => 'EdGaming', 'status' => 'approved']);

        $this->seed(EdGamingCatalogSeeder::class);

        $products = Product::query()->where('seller_id', $seller->id)->with(['images', 'variants'])->get();
        $this->assertGreaterThanOrEqual(40, $products->count());

        foreach ($products as $product) {
            $this->assertTrue($product->isPurchasable(), $product->name);
            $this->assertGreaterThan(0, $product->variants->sum('stock_quantity'), $product->name);
            $this->assertSame((int) $product->stock_quantity, (int) $product->variants->sum('stock_quantity'), $product->name);
            $this->assertSame(1, $product->images->where('is_primary', true)->count(), $product->name);
            $product->images->each(fn ($image) => Storage::disk('public')->assertExists($image->path));
            $this->assertTrue(InventoryTransaction::query()->where('product_id', $product->id)->exists(), $product->name);
        }

        $this->seed(EdGamingCatalogSeeder::class);

        $this->assertSame($products->count(), Product::query()->where('seller_id', $seller->id)->count());
    }

    public function test_it_changes_nothing_when_the_store_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->seed(EdGamingCatalogSeeder::class);
    }
}
