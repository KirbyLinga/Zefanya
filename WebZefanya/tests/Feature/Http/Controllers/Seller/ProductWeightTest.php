<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class ProductWeightTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function payload(): array
    {
        return [
            ...$this->publicationDefaults(),
            'name' => 'Weighted item', 'price' => 100, 'stock_quantity' => 2,
            'status' => 'active', 'weight_grams' => 1001, 'weight' => 1.001, 'weight_unit' => 'kg',
            'images' => [UploadedFile::fake()->createWithContent('item.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))],
        ];
    }

    public function test_kilograms_are_saved_as_grams_for_the_product_and_default_variant(): void
    {
        Storage::fake('public');
        $this->actingAs(Seller::factory()->create(), 'seller')
            ->post(route('seller.products.store'), $this->payload())->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame(1001, $product->weight_grams);
        $this->assertSame(1001, $product->variants()->sole()->weight_grams);
    }

    public static function invalidWeights(): array
    {
        return [
            'missing' => [null, 'g'], 'zero' => [0, 'g'], 'negative' => [-1, 'g'],
            'subgram' => [0.0005, 'kg'], 'over maximum' => [100.001, 'kg'],
            'invalid unit' => [1, 'lb'], 'not numeric' => ['heavy', 'g'],
        ];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_product_weights_cannot_be_published(mixed $weight, string $unit): void
    {
        Storage::fake('public');
        $data = array_replace($this->payload(), ['weight' => $weight, 'weight_unit' => $unit]);
        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $data)
            ->assertSessionHasErrors();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_each_variant_requires_its_own_weight_and_edits_preserve_it(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload() + [
            'variants_exist' => '1', 'options' => [['name' => 'Size', 'values' => ['S', 'L']]],
            'variants' => [
                ['values' => ['S'], 'price' => 100, 'stock_quantity' => 1, 'weight' => 1, 'weight_unit' => 'g'],
                ['values' => ['L'], 'price' => 150, 'stock_quantity' => 1, 'weight' => 100, 'weight_unit' => 'kg'],
            ],
        ];
        $invalid = $data;
        unset($invalid['variants'][1]['weight']);
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $invalid)
            ->assertSessionHasErrors('variants.1.weight');
        $this->assertDatabaseCount('products', 0);
        $this->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame([1, 100000], $product->variants()->orderBy('id')->pluck('weight_grams')->all());

        $data['variants'][0]['weight'] = 250;
        unset($data['images']);
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->load('variants'));
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame([250, 100000], $product->variants()->orderBy('id')->pluck('weight_grams')->all());

        $product->variants()->first()->update(['weight_grams' => null]);
        $product->update(['status' => 'inactive']);
        $this->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('weight');
        $this->assertSame('inactive', $product->fresh()->status);
    }

    public function test_status_toggle_blocks_an_existing_weightless_product(): void
    {
        $seller = Seller::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());
        $product->forceFill(['status' => 'draft', 'weight_grams' => 0])->save();
        $product->variants()->update(['weight_grams' => 0]);
        $this->actingAs($seller, 'seller')->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('weight');
        $this->assertSame('draft', $product->fresh()->status);
    }
}
