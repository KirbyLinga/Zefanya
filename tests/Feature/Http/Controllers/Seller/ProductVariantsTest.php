<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Services\ProductPublishingService;
use App\Services\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create();
    }

    private function mine(array $attributes = []): Product
    {
        return Product::factory()->create(['seller_id' => $this->seller->id] + $attributes);
    }

    /**
     * A 1×1 transparent PNG — content-based MIME detection reads it as
     * image/png, which passes the request's image rule without GD.
     */
    private function fakeImage(): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        );

        return UploadedFile::fake()->createWithContent('tee.png', $png);
    }

    /**
     * A valid variant submission for a Color × Size product. Array-shaped so
     * it works BOTH as HTTP POST fields and as a direct VariantSyncService
     * payload (the service contract takes lists).
     */
    private function variantPayload(array $overrides = []): array
    {
        return array_merge([
            'variants_exist' => '1',
            'options' => [
                ['name' => 'Color', 'values' => ['Red', 'Blue']],
                ['name' => 'Size', 'values' => ['M', 'L']],
            ],
            'variants' => [
                ['values' => ['Red', 'M'], 'sku' => 'RM', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '100.00', 'stock_quantity' => '5', 'is_available' => '1'],
                ['values' => ['Red', 'L'], 'sku' => 'RL', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '110.00', 'stock_quantity' => '6', 'is_available' => '1'],
                ['values' => ['Blue', 'M'], 'sku' => 'BM', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '120.00', 'stock_quantity' => '7', 'is_available' => '0'],
                ['values' => ['Blue', 'L'], 'sku' => 'BL', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '130.00', 'stock_quantity' => '8', 'is_available' => '1'],
            ],
        ], $overrides);
    }

    // ── Creation ─────────────────────────────────────────────────────────────

    public function test_creating_a_product_with_option_combinations(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.products.store'), [
                'name' => 'Variant Tee',
                'price' => '100.00',
                'stock_quantity' => '26',
                'low_stock_threshold' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
                'images' => [$this->fakeImage()],
            ] + $this->variantPayload())
            ->assertRedirect(route('seller.products.index'));

        $product = Product::query()->where('name', 'Variant Tee')->firstOrFail();

        $this->assertSame(2, $product->options()->count());
        $this->assertSame(4, $product->variants()->count(), 'Color×Size = 4 combinations');
        $this->assertSame(0, $product->variants()->whereDoesntHave('optionValues')->count(), 'no stray Default row');

        $red = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereHas('optionValues', fn ($q) => $q->where('value', 'Red'))
            ->whereHas('optionValues', fn ($q) => $q->where('value', 'M'))
            ->first();

        $this->assertNotNull($red);
        $this->assertSame('RM', $red->sku);
        $this->assertSame('100.00', (string) $red->price);
        $this->assertSame(5, $red->stock_quantity);
        $this->assertTrue($red->is_available);

        $disabled = $product->variants()->where('is_available', false)->first();
        $this->assertNotNull($disabled, 'Blue/M was submitted disabled');
        $this->assertSame(7, $disabled->stock_quantity);

        // Rollups: price = min(100,110,120,130), stock = sum(5,6,7,8).
        $product->refresh();
        $this->assertSame('100.00', (string) $product->price);
        $this->assertSame(26, $product->stock_quantity);
    }

    public function test_every_created_product_gets_a_default_variant_even_without_options(): void
    {
        $product = $this->mine(['price' => 55.5, 'stock_quantity' => 12]);

        $this->assertSame(1, $product->variants()->count());
        $this->assertFalse($product->hasVariants());

        $default = $product->variants()->first();
        $this->assertSame('55.50', (string) $default->price);
        $this->assertSame(12, $default->stock_quantity);
        $this->assertSame('Default', $default->label());
    }

    public function test_disabled_combination_is_not_purchaseable_but_keeps_stock(): void
    {
        $product = $this->mine();
        app(VariantSyncService::class)->sync($product, $this->variantPayload()['options'], $this->variantPayload()['variants'], true);

        $disabled = $product->variants()->where('is_available', false)->firstOrFail();

        $this->assertFalse($disabled->isPurchaseable());
        $this->assertSame(7, $disabled->stock_quantity, 'disabling must not zero the stock');
        $this->assertTrue($product->variants()->where('is_available', true)->first()->isPurchaseable());
    }

    // ── Editing ──────────────────────────────────────────────────────────────

    public function test_editing_price_and_stock_per_variant_is_independent(): void
    {
        $product = $this->mine();
        app(VariantSyncService::class)->sync($product, $this->variantPayload()['options'], $this->variantPayload()['variants'], true);

        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->put(route('seller.products.update', $product), [
                'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
                'name' => $product->name,
                'description' => $product->description,
                'price' => '100.00',
                'stock_quantity' => '26',
                'low_stock_threshold' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
            ] + $this->variantPayload([
                'variants' => [
                    ['values' => ['Red', 'M'], 'sku' => 'RM2', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '199.00', 'stock_quantity' => '50', 'is_available' => '1'],
                    ['values' => ['Red', 'L'], 'sku' => 'RL', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '110.00', 'stock_quantity' => '6', 'is_available' => '1'],
                    ['values' => ['Blue', 'M'], 'sku' => 'BM', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '120.00', 'stock_quantity' => '7', 'is_available' => '1'],
                    ['values' => ['Blue', 'L'], 'sku' => 'BL', 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '130.00', 'stock_quantity' => '8', 'is_available' => '1'],
                ],
            ]))
            ->assertRedirect(route('seller.products.index'));

        $red = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('sku', 'RM2')
            ->firstOrFail();

        $this->assertSame('199.00', (string) $red->price);
        $this->assertSame(50, $red->stock_quantity);

        // Untouched rows survive an edit unchanged.
        $this->assertSame('130.00', (string) $product->variants()->where('sku', 'BL')->value('price'));

        $product->refresh();
        $this->assertSame(71, $product->stock_quantity, 'rollup = 50+6+7+8');
    }

    public function test_removing_an_option_value_drops_its_combinations(): void
    {
        $product = $this->mine();
        $payload = $this->variantPayload();
        $payload['variants'] = array_map(fn ($row) => array_replace($row, ['stock_quantity' => 0]), $payload['variants']);
        app(VariantSyncService::class)->sync($product, $payload['options'], $payload['variants'], true);

        // Drop the whole Blue axis value: Blue/M and Blue/L go away.
        app(VariantSyncService::class)->sync(
            $product,
            [
                ['name' => 'Color', 'values' => ['Red']],
                ['name' => 'Size', 'values' => ['M', 'L']],
            ],
            [
                ['values' => ['Red', 'M'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => 100, 'stock_quantity' => 5, 'is_available' => true],
                ['values' => ['Red', 'L'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => 110, 'stock_quantity' => 6, 'is_available' => true],
            ],
            true,
        );

        // 4 combinations → 2; axes stay at 2 (Color just narrowed to Red);
        // the 2 Blue value rows are deleted with their option values.
        $this->assertSame(2, $product->variants()->count());
        $this->assertSame(2, $product->options()->count());
        $this->assertSame(
            3,
            ProductOptionValue::query()->whereHas('option', fn ($q) => $q->where('product_id', $product->id))->count(),
            'Red, M, L survive — Blue and its value rows are gone',
        );
    }

    public function test_clearing_options_cannot_destroy_existing_inventory_history(): void
    {
        $product = $this->mine();
        app(VariantSyncService::class)->sync($product, $this->variantPayload()['options'], $this->variantPayload()['variants'], true);

        // Submit with variants_exist=0 → legacy single-SKU mode.
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->put(route('seller.products.update', $product), [
                'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
                'name' => $product->name,
                'price' => '88.00',
                'stock_quantity' => '13',
                'low_stock_threshold' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
            ])
            ->assertRedirect(route('seller.products.index'))->assertSessionHasErrors('options');

        $product->refresh();
        $this->assertTrue($product->hasVariants());
        $this->assertSame(4, $product->variants()->count());
        $this->assertSame(26, $product->stock_quantity);
    }

    // ── Pre-variant products keep working ──────────────────────────────────

    public function test_products_created_before_variants_still_work(): void
    {
        $product = $this->mine(['price' => 42.0, 'stock_quantity' => 9]);

        // Legacy product: hasVariants() false, rollup mirrors the product.
        $this->assertFalse($product->hasVariants());
        $this->assertSame('42.00', (string) $product->price);
        $this->assertSame(9, $product->stock_quantity);

        // Saving without variant fields keeps the Default variant intact.
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->put(route('seller.products.update', $product), [
                'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
                'name' => $product->name,
                'price' => '45.00',
                'stock_quantity' => '10',
                'low_stock_threshold' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
            ])
            ->assertRedirect(route('seller.products.index'));

        $product->refresh();
        $this->assertSame(1, $product->variants()->count());
        $this->assertSame('45.00', (string) $product->price);
        $this->assertSame('45.00', (string) $product->variants()->first()->price, 'Default mirrors the product price');
    }

    public function test_dropping_a_referenced_variant_is_rejected(): void
    {
        $product = $this->mine();
        app(VariantSyncService::class)->sync($product, $this->variantPayload()['options'], $this->variantPayload()['variants'], true);

        $dropped = $product->variants()->where('sku', 'BM')->firstOrFail();
        CartItem::query()->create([
            'buyer_id' => Buyer::factory()->create()->id,
            'product_id' => $product->id,
            'variant_id' => $dropped->id,
            'quantity' => 2,
        ]);

        try {
            app(VariantSyncService::class)->sync(
                $product,
                [['name' => 'Color', 'values' => ['Red', 'Blue']], ['name' => 'Size', 'values' => ['M', 'L']]],
                [
                    ['values' => ['Red', 'M'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => 100, 'stock_quantity' => 5, 'is_available' => true],
                    ['values' => ['Red', 'L'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => 110, 'stock_quantity' => 6, 'is_available' => true],
                    ['values' => ['Blue', 'L'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => 130, 'stock_quantity' => 8, 'is_available' => true],
                ],
                true,
            );

            $this->fail('Referenced variants must not be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }
        $this->assertDatabaseHas('product_variants', ['id' => $dropped->id]);
        $this->assertDatabaseHas('cart_items', ['variant_id' => $dropped->id]);
    }

    public function test_more_than_two_option_axes_are_rejected(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->post(route('seller.products.store'), [
                'name' => 'Too Many Axes',
                'price' => '10.00',
                'stock_quantity' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
                'images' => [$this->fakeImage()],
            ] + $this->variantPayload([
                'options' => [
                    ['name' => 'Color', 'values' => ['Red']],
                    ['name' => 'Size', 'values' => ['M']],
                    ['name' => 'Material', 'values' => ['Cotton']],
                ],
                'variants' => [
                    ['values' => ['Red', 'M', 'Cotton'], 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'price' => '10.00', 'stock_quantity' => '5', 'is_available' => '1'],
                ],
            ]))
            ->assertSessionHasErrors('options');
    }

    // ── Per-seller SKU uniqueness (app-layer — migration 3 only indexes sku) ─

    /**
     * The standard 2×2 variant payload with caller-controlled SKUs
     * (null = blank / skipped).
     *
     * @param  array<int, string|null>  $skus
     */
    private function variantPayloadWithSkus(array $skus): array
    {
        $variants = $this->variantPayload()['variants'];

        foreach ($variants as $index => $row) {
            $variants[$index]['sku'] = $skus[$index] ?? null;
        }

        return $this->variantPayload(['variants' => $variants]);
    }

    /**
     * A full store() payload for a Color×Size product with the given SKUs.
     *
     * @param  array<int, string|null>  $skus
     */
    private function storePayload(string $name, array $skus): array
    {
        return [
            'name' => $name,
            'price' => '100.00',
            'stock_quantity' => '4',
            'low_stock_threshold' => '5',
            'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
            'images' => [$this->fakeImage()],
        ] + $this->variantPayloadWithSkus($skus);
    }

    public function test_two_different_sellers_can_use_the_same_sku(): void
    {
        $otherSeller = Seller::factory()->create();

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.products.store'), $this->storePayload('Seller A Tee', ['SHIRT-BLK-M']))
            ->assertRedirect(route('seller.products.index'));

        $this->actingAs($otherSeller, 'seller')
            ->post(route('seller.products.store'), $this->storePayload('Seller B Tee', ['SHIRT-BLK-M']))
            ->assertRedirect(route('seller.products.index'));

        $this->assertSame(
            2,
            ProductVariant::query()->where('sku', 'SHIRT-BLK-M')->count(),
            'SKUs are scoped per seller — two shops may use the same code',
        );
    }

    public function test_seller_cannot_reuse_a_sku_on_another_of_their_products(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.products.store'), $this->storePayload('First Tee', ['SHIRT-BLK-M']))
            ->assertRedirect(route('seller.products.index'));

        // Different case + padding still counts as the same SKU.
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->post(route('seller.products.store'), $this->storePayload('Second Tee', ['  shirt-blk-m  ']))
            ->assertSessionHasErrors('variants.0.sku');

        $message = (string) session('errors')->first('variants.0.sku');
        $this->assertStringContainsString('shirt-blk-m', $message, 'The error must name the offending SKU');
        $this->assertStringContainsString("another of the seller's products", $message);
        $this->assertSame(
            0,
            Product::query()->where('name', 'Second Tee')->count(),
            'The duplicate submission must not create a product',
        );
    }

    public function test_editing_a_product_and_resubmitting_its_own_skus_is_not_a_duplicate(): void
    {
        $product = $this->mine();
        // The product owns RM / RL / BM / BL.
        app(VariantSyncService::class)->sync(
            $product,
            $this->variantPayload()['options'],
            $this->variantPayload()['variants'],
            true,
        );

        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->put(route('seller.products.update', $product), [
                'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
                'name' => $product->name,
                'description' => $product->description,
                'price' => '100.00',
                'stock_quantity' => '26',
                'low_stock_threshold' => '5',
                'weight' => 250, 'weight_unit' => 'g', 'status' => 'active',
            ] + $this->variantPayload())
            ->assertRedirect(route('seller.products.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_duplicate_skus_within_one_payload_are_rejected(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->post(route('seller.products.store'), $this->storePayload('Dup Tee', ['DUP-1', 'dup-1']))
            ->assertSessionHasErrors('variants.1.sku');

        $this->assertSame(0, Product::query()->where('name', 'Dup Tee')->count());
    }

    public function test_blank_skus_on_multiple_variants_are_allowed(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.products.store'), $this->storePayload('Blank SKUs', [null, '   ', null, null]))
            ->assertRedirect(route('seller.products.index'));

        $product = Product::query()->where('name', 'Blank SKUs')->firstOrFail();

        $this->assertSame(4, $product->variants()->count());
        $this->assertSame(
            4,
            $product->variants()->whereNull('sku')->count(),
            'Blank SKUs normalize to NULL and never collide',
        );
    }
}
