<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductCardImagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pages_render_image_urls_even_when_local_storage_cannot_see_the_file(): void
    {
        Storage::fake('public');
        $this->withoutVite();
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => 'active', 'stock_quantity' => 10,
        ]);
        $product->images()->create(['path' => 'products/shared-cover.jpg', 'is_primary' => true, 'sort_order' => 0]);
        foreach ([route('home'), route('buyer.home'), route('buyer.products.index')] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $images = (new \DOMXPath($document))->query('//article//img[@src="/storage/products/shared-cover.jpg"]');
            $this->assertGreaterThan(0, $images->length, $url);
        }
        Storage::disk('public')->assertMissing('products/shared-cover.jpg');
    }

    public function test_product_detail_uses_public_image_urls_when_the_default_disk_is_private(): void
    {
        Storage::fake('public');
        $this->withoutVite();
        config(['filesystems.default' => 'local', 'filesystems.disks.public.url' => 'http://localhost:8000/storage']);
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => 'active', 'stock_quantity' => 10,
        ]);
        $product->images()->create(['path' => 'products/detail-cover.jpg', 'is_primary' => true, 'sort_order' => 0]);

        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $image = (new \DOMXPath($document))->query('//img[@id="pdp-main-img"]')->item(0);

        $this->assertNotNull($image);
        $this->assertSame('/storage/products/detail-cover.jpg', $image->getAttribute('src'));
    }

    public function test_product_detail_prefers_an_existing_image_over_a_missing_primary_upload(): void
    {
        Storage::fake('public');
        $this->withoutVite();
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => 'active', 'stock_quantity' => 10,
        ]);
        $product->images()->create(['path' => 'products/missing.jpg', 'is_primary' => true, 'sort_order' => 0]);
        $product->images()->create(['path' => 'products/available.jpg', 'is_primary' => false, 'sort_order' => 1]);
        Storage::disk('public')->put('products/available.jpg', 'image');

        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $image = (new \DOMXPath($document))->query('//img[@id="pdp-main-img"]')->item(0);

        $this->assertNotNull($image);
        $this->assertSame('/storage/products/available.jpg', $image->getAttribute('src'));
    }

    public function test_product_gallery_exposes_every_uploaded_angle_as_a_selectable_thumbnail(): void
    {
        Storage::fake('public');
        $this->withoutVite();
        $product = Product::factory()->create(['status' => 'active', 'stock_quantity' => 10]);
        $paths = ['products/front.jpg', 'products/left.jpg', 'products/right.png'];
        foreach ($paths as $index => $path) {
            $product->images()->create(['path' => $path, 'is_primary' => $index === 0, 'sort_order' => $index]);
            Storage::disk('public')->put($path, 'image');
        }
        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " js-pdp-thumb ")]');
        $this->assertSame(3, $buttons->length);
        foreach ($buttons as $index => $button) {
            $this->assertSame('/storage/'.$paths[$index], $button->getAttribute('data-src'));
            $this->assertSame($index === 0 ? 'true' : 'false', $button->getAttribute('aria-pressed'));
            $this->assertSame('View photo '.($index + 1).' of 3', $button->getAttribute('aria-label'));
        }
        $this->assertStringContainsString('object-contain', $xpath->query('//img[@id="pdp-main-img"]')->item(0)->getAttribute('class'));
    }

    public function test_cards_show_a_placeholder_without_image_records(): void
    {
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => 'active', 'stock_quantity' => 10,
        ]);
        $html = view('Components.buyer.product-card', ['product' => $product])->render();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('data-lucide="package"', $html);
    }
}
