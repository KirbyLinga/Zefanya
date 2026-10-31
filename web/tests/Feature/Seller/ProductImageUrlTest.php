<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageUrlTest extends TestCase
{
    public function test_local_catalog_images_use_the_current_site_instead_of_the_configured_development_host(): void
    {
        config(['filesystems.disks.public.url' => 'http://localhost:8000/storage']);
        Storage::fake('public');
        Storage::disk('public')->put('products/15/shirt.jpg', 'image');
        $product = new Product;
        $product->setRelation('images', new Collection([new ProductImage(['path' => 'products/15/shirt.jpg', 'is_primary' => true])]));
        $this->assertSame('/storage/products/15/shirt.jpg', $product->catalogImageUrl());
    }

    public function test_missing_cover_uses_an_existing_alternative_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/15/color.jpg', 'image');
        $product = new Product;
        $product->setRelation('images', new Collection([
            new ProductImage(['path' => 'products/15/missing.jpg', 'is_primary' => true]),
            new ProductImage(['path' => 'products/15/color.jpg', 'is_primary' => false]),
        ]));
        $this->assertSame('/storage/products/15/color.jpg', $product->catalogImageUrl());
    }

    public function test_a_product_with_no_existing_files_keeps_the_placeholder(): void
    {
        Storage::fake('public');
        $product = new Product;
        $product->setRelation('images', new Collection([new ProductImage(['path' => 'products/15/missing.jpg'])]));
        $this->assertNull($product->catalogImageUrl());
    }
}
