<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class ProductPricePrecisionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function payload(string $price): array
    {
        return [
            ...$this->publicationDefaults(),
            'name' => 'Precision test product', 'description' => 'A product used to verify price precision.',
            'price' => $price, 'weight' => 250, 'weight_unit' => 'g', 'stock_quantity' => 5, 'status' => 'active',
            'images' => [UploadedFile::fake()->createWithContent('product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))],
        ];
    }

    public static function excessPrecision(): array
    {
        return [['199.999'], ['1.005'], ['1.000'], ['0.009'], ['1e-3']];
    }

    #[DataProvider('excessPrecision')]
    public function test_new_product_prices_with_excess_precision_are_rejected_without_writes(string $price): void
    {
        Storage::fake('public');

        $this->actingAs(Seller::factory()->create(), 'seller')
            ->post(route('seller.products.store'), $this->payload($price))->assertSessionHasErrors('price');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('excessPrecision')]
    public function test_price_edits_with_excess_precision_preserve_existing_prices_and_stock(string $price): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => '19.99', 'stock_quantity' => 5]);
        $payload = $this->payload($price) + ['inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'))];

        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), $payload)->assertSessionHasErrors('price');

        $this->assertSame('19.99', $product->fresh()->price);
        $this->assertSame('19.99', $product->variants()->sole()->price);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    #[DataProvider('excessPrecision')]
    public function test_variant_prices_cannot_be_silently_rounded(string $price): void
    {
        Storage::fake('public');
        $payload = $this->payload('1.00') + [
            'variants_exist' => '1', 'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [
                ['values' => ['S'], 'price' => $price, 'weight' => 250, 'weight_unit' => 'g', 'stock_quantity' => 5],
                ['values' => ['M'], 'price' => '2.00', 'weight' => 250, 'weight_unit' => 'g', 'stock_quantity' => 5],
            ],
        ];

        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $payload)->assertSessionHasErrors('variants.0.price');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public static function validPrices(): array
    {
        return [['1', '1.00'], ['1.0', '1.00'], ['1.01', '1.01'], ['999999.99', '999999.99'], ['1000000.00', '1000000.00']];
    }

    #[DataProvider('validPrices')]
    public function test_valid_prices_are_saved_exactly_without_rounding(string $input, string $expected): void
    {
        Storage::fake('public');

        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $this->payload($input))
            ->assertSessionHasNoErrors()->assertRedirect(route('seller.products.index'));

        $product = Product::query()->sole();
        $this->assertSame($expected, $product->price);
        $this->assertSame($expected, $product->variants()->sole()->price);
    }

    #[DataProvider('excessPrecision')]
    public function test_service_rejects_product_price_precision_before_casting_or_writing(string $price): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload($price) + ['weight_grams' => 250];
        try {
            app(ProductPublishingService::class)->save(new Product, $seller, $data);
            $this->fail('The publishing service accepted a price that requires rounding.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('price', $exception->errors());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('excessPrecision')]
    public function test_service_rejects_variant_price_precision_before_casting_or_writing(string $price): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload('1.00') + [
            'variants_exist' => true, 'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [
                ['values' => ['S'], 'price' => $price, 'weight_grams' => 250, 'stock_quantity' => 5],
                ['values' => ['M'], 'price' => '2.00', 'weight_grams' => 250, 'stock_quantity' => 5],
            ],
        ];
        try {
            app(ProductPublishingService::class)->save(new Product, $seller, $data);
            $this->fail('The publishing service accepted a variant price that requires rounding.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variants.0.price', $exception->errors());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
