<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class Rule06VariationLimitsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function payload(array $axes): array
    {
        $combinations = [[]];
        foreach ($axes as $axis) {
            $combinations = array_merge(...array_map(fn ($row) => array_map(fn ($value) => [...$row, $value], $axis['values']), $combinations));
        }
        $prices = ['S' => '99.00', 'M' => '129.00', 'L' => '149.00'];

        return [
            ...$this->publicationDefaults(),
            'name' => 'Buri Coin Purse', 'price' => '99.00', 'stock_quantity' => 0, 'status' => 'active',
            'weight' => 250, 'weight_unit' => 'g', 'variants_exist' => '1', 'options' => $axes,
            'images' => [UploadedFile::fake()->createWithContent('purse.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))],
            'variants' => array_map(fn ($values, $index) => [
                'values' => $values, 'sku' => 'BCP-'.$index, 'price' => $prices[$values[1] ?? ''] ?? '99.00',
                'stock_quantity' => 5, 'is_available' => 1, 'weight' => 250, 'weight_unit' => 'g',
            ], $combinations, array_keys($combinations)),
        ];
    }

    public function test_sel_var_001_creates_six_independent_combinations_visible_to_buyers(): void
    {
        Storage::fake('public');
        $this->withoutVite();
        $payload = $this->payload([['name' => 'Color', 'values' => ['Red', 'Blue']], ['name' => 'Size', 'values' => ['S', 'M', 'L']]]);

        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $payload)->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $rows = $product->variants()->with('optionValues')->orderBy('id')->get();
        $this->assertCount(6, $rows);
        $this->assertSame(['99.00', '129.00', '149.00', '99.00', '129.00', '149.00'], $rows->pluck('price')->all());
        $this->assertSame([5, 5, 5, 5, 5, 5], $rows->pluck('stock_quantity')->all());
        $this->assertSame(['BCP-0', 'BCP-1', 'BCP-2', 'BCP-3', 'BCP-4', 'BCP-5'], $rows->pluck('sku')->all());
        $this->assertSame(['Red-S', 'Red-M', 'Red-L', 'Blue-S', 'Blue-M', 'Blue-L'], $rows->map(fn ($row) => $row->optionValues->pluck('value')->implode('-'))->all());
        $this->get(route('buyer.products.show', $product))->assertOk()->assertSee('Color')->assertSee('Size')
            ->assertViewHas('variantData', fn ($data) => count($data) === 6);
    }

    public function test_sel_var_002_rejects_a_third_axis_and_preserves_existing_variant_and_inventory_history(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $payload = $this->payload([['name' => 'Color', 'values' => ['Red', 'Blue']], ['name' => 'Size', 'values' => ['S', 'M', 'L']]]);
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $payload)->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $before = $product->variants()->orderBy('id')->get()->toArray();
        $ledger = InventoryTransaction::query()->orderBy('id')->get()->toArray();
        $payload['options'][] = ['name' => 'Material', 'values' => ['Abaca', 'Buri']];
        $payload['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        unset($payload['images']);

        $this->put(route('seller.products.update', $product), $payload)->assertSessionHasErrors('options');

        $this->assertSame($before, $product->variants()->orderBy('id')->get()->toArray());
        $this->assertSame($ledger, InventoryTransaction::query()->orderBy('id')->get()->toArray());
    }

    public static function optionBoundaries(): array
    {
        return [[20, true, 0], [21, false, 0], [20, true, 1], [21, false, 1]];
    }

    #[DataProvider('optionBoundaries')]
    public function test_sel_var_003_enforces_twenty_options_on_the_server(int $count, bool $accepted, int $axis): void
    {
        Storage::fake('public');
        $values = array_map(fn ($number) => 'Option '.$number, range(1, $count));
        $payload = $this->payload($axis === 0 ? [['name' => 'Color', 'values' => $values]] : [['name' => 'Color', 'values' => ['Red']], ['name' => 'Size', 'values' => $values]]);

        $response = $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $payload);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(20, Product::query()->sole()->variants()->count());
        } else {
            $response->assertSessionHasErrors('options.'.$axis.'.values');
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);
        }
    }

    public function test_two_valid_twenty_option_axes_are_not_blocked_by_an_unsupported_fifty_combination_cap(): void
    {
        Storage::fake('public');
        $payload = $this->payload([
            ['name' => 'Color', 'values' => array_map(fn ($number) => 'Color '.$number, range(1, 20))],
            ['name' => 'Size', 'values' => array_map(fn ($number) => 'Size '.$number, range(1, 20))],
        ]);

        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(400, Product::query()->sole()->variants()->count());
        $this->assertSame(2000, Product::query()->sole()->stock_quantity);
    }

    public function test_direct_variant_service_calls_also_reject_twenty_one_options_before_mutation(): void
    {
        $product = Product::factory()->create();
        $before = $product->variants()->get()->toArray();
        $payload = $this->payload([['name' => 'Color', 'values' => array_map(fn ($number) => 'Color '.$number, range(1, 21))]]);

        try {
            app(VariantSyncService::class)->sync($product, $payload['options'], $payload['variants'], true);
            $this->fail('Twenty-one options must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options.0.values', $exception->errors());
        }

        $this->assertSame($before, $product->variants()->get()->toArray());
    }
}
