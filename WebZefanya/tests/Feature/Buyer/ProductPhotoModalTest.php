<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPhotoModalTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('photoCounts')]
    public function test_product_photos_use_buttons_linked_to_an_on_page_dialog(int $count): void
    {
        $product = Product::factory()->create(['status' => 'active']);
        for ($index = 0; $index < $count; $index++) {
            $product->images()->create(['path' => 'products/modal-'.$index.'.png', 'is_primary' => $index === 0, 'sort_order' => $index]);
        }
        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//dialog[@id="pdp-image-modal" and @aria-labelledby="pdp-image-modal-title"]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="pdp-image-open" and @aria-haspopup="dialog" and @aria-controls="pdp-image-modal"]//img[@id="pdp-main-img"]')->length);
        $this->assertSame($count > 1 ? $count : 0, $xpath->query('//button[contains(@class,"js-pdp-thumb") and @aria-controls="pdp-image-modal"]')->length);
        $this->assertSame(0, $xpath->query('//button[@id="pdp-image-open" or @id="pdp-maximize"][@disabled]')->length);
    }

    public static function photoCounts(): array
    {
        return ['one photo' => [1], 'cover and three angles' => [4]];
    }

    public function test_product_without_a_photo_disables_the_viewer_triggers(): void
    {
        $product = Product::factory()->create(['status' => 'active']);
        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//button[@id="pdp-image-open" or @id="pdp-maximize"][@disabled]')->length);
    }
}
