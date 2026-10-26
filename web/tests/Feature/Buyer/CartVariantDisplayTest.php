<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Services\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartVariantDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $options = []): Product
    {
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'name' => 'Cart Display Shirt', 'price' => '120.25', 'stock_quantity' => $options === [] ? 20 : 0,
        ]);
        if ($options !== []) {
            app(VariantSyncService::class)->sync($product,
                array_map(fn ($name, $values) => ['name' => $name, 'values' => $values], array_keys($options), array_values($options)),
                [['values' => array_map(fn ($values) => $values[0], array_values($options)), 'price' => '120.25', 'stock_quantity' => 20, 'is_available' => true]], true);
        }

        return $product;
    }

    private function photo(Product $product, string $path, ?int $variantId = null, bool $exists = true, bool $primary = false): void
    {
        $product->images()->create(['path' => $path, 'variant_id' => $variantId, 'is_primary' => $primary, 'sort_order' => $product->images()->count()]);
        if ($exists) {
            Storage::disk('public')->put($path, 'fixture image');
        }
    }

    private function row(string $html, int $id): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new \DOMXPath($document))->query('//div[@data-item-id="'.$id.'"]');
        $this->assertCount(1, $nodes);

        return $document->saveHTML($nodes->item(0));
    }

    public static function optionTypes(): array
    {
        return [
            'color and size' => [['Color' => ['Black'], 'Size' => ['Small']], 'Black / Small'],
            'color only' => [['Color' => ['Black']], 'Black'],
            'size only' => [['Size' => ['Small']], 'Small'],
            'optionless' => [[], null],
        ];
    }

    #[DataProvider('optionTypes')]
    public function test_both_carts_show_the_selected_image_label_price_and_quantity(array $options, ?string $label): void
    {
        Storage::fake('public');
        $buyer = Buyer::factory()->create();
        $product = $this->product($options);
        $variant = $product->variants()->sole();
        $this->photo($product, 'cart/cover.jpg', primary: true);
        $this->photo($product, 'cart/selected.jpg', $variant->id);
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);

        $this->actingAs($buyer, 'buyer');
        $row = $this->row($this->get(route('buyer.cart.index'))->assertOk()->getContent(), $line->id);
        $mini = $this->getJson(route('buyer.cart.mini'))->assertOk();

        $this->assertStringContainsString('/storage/cart/selected.jpg', $row);
        $this->assertStringNotContainsString('/storage/cart/cover.jpg', $row);
        $this->assertStringContainsString('Cart Display Shirt', $row);
        $this->assertStringContainsString('data-unit-centavos="12025"', $row);
        $this->assertStringContainsString('data-line-total-centavos="24050"', $row);
        $this->assertStringContainsString('240.50', $row);
        $this->assertStringContainsString('>2</span>', $row);
        if ($label !== null) {
            $this->assertStringContainsString($label, $row);
        } else {
            $this->assertStringNotContainsString('Default', $row);
        }
        $mini->assertJsonPath('items.0.id', $line->id)
            ->assertJsonPath('items.0.image_url', '/storage/cart/selected.jpg')
            ->assertJsonPath('items.0.variant', $label)
            ->assertJsonPath('items.0.price_centavos', 12025)
            ->assertJsonPath('items.0.price', "\u{20B1}120.25")
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('subtotal_centavos', 24050);
    }

    public static function imageFallbacks(): array
    {
        return [
            'no variant image' => [false, false, true, '/storage/cart/cover.jpg'],
            'missing variant file' => [true, false, true, '/storage/cart/cover.jpg'],
            'missing primary with valid variant alternative' => [true, true, true, '/storage/cart/alternative.jpg'],
            'no image records' => [false, false, false, null],
            'all variant files missing' => [true, false, false, null],
        ];
    }

    #[DataProvider('imageFallbacks')]
    public function test_both_carts_share_safe_image_fallbacks(bool $missing, bool $alternative, bool $cover, ?string $expected): void
    {
        Storage::fake('public');
        $buyer = Buyer::factory()->create();
        $product = $this->product(['Color' => ['Black']]);
        $variant = $product->variants()->sole();
        if ($cover) {
            $this->photo($product, 'cart/cover.jpg', primary: true);
        }
        if ($missing) {
            $this->photo($product, 'cart/missing.jpg', $variant->id, exists: false, primary: true);
        }
        if ($alternative) {
            $this->photo($product, 'cart/alternative.jpg', $variant->id);
        }
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id]);

        $this->actingAs($buyer, 'buyer');
        $row = $this->row($this->get(route('buyer.cart.index'))->assertOk()->getContent(), $line->id);
        $this->getJson(route('buyer.cart.mini'))->assertOk()->assertJsonPath('items.0.image_url', $expected);

        $this->assertStringNotContainsString('/storage/cart/missing.jpg', $row);
        if ($expected !== null) {
            $this->assertStringContainsString($expected, $row);
        } else {
            $this->assertStringNotContainsString('<img', $row);
        }
    }

    public function test_legacy_optionless_line_uses_default_price_without_a_fake_label(): void
    {
        Storage::fake('public');
        $buyer = Buyer::factory()->create();
        $product = $this->product();
        $product->variants()->sole()->update(['price' => '175.00']);
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => null, 'quantity' => 2]);

        $this->actingAs($buyer, 'buyer');
        $row = $this->row($this->get(route('buyer.cart.index'))->assertOk()->getContent(), $line->id);
        $this->getJson(route('buyer.cart.mini'))->assertOk()
            ->assertJsonPath('items.0.variant', null)
            ->assertJsonPath('items.0.price_centavos', 17500)
            ->assertJsonPath('items.0.image_url', null)
            ->assertJsonPath('subtotal_centavos', 35000);

        $this->assertStringNotContainsString('Default', $row);
        $this->assertStringContainsString('175.00 each', $row);
        $this->assertStringContainsString('350.00', $row);
    }

    public function test_multiple_variants_remain_distinct_through_quantity_updates_and_removal(): void
    {
        Storage::fake('public');
        $buyer = Buyer::factory()->create();
        $product = $this->product(['Color' => ['Black']]);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Black', 'White']]],
            [
                ['values' => ['Black'], 'price' => '120.25', 'stock_quantity' => 20, 'is_available' => true],
                ['values' => ['White'], 'price' => '250.50', 'stock_quantity' => 20, 'is_available' => true],
            ], true);
        $variants = $product->variants()->with('optionValues')->get()->keyBy(fn ($variant) => $variant->label());
        $lines = [];
        foreach (['Black' => 2, 'White' => 3] as $color => $quantity) {
            $this->photo($product, 'cart/'.$color.'.jpg', $variants[$color]->id);
            $lines[$color] = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variants[$color]->id, 'quantity' => $quantity]);
        }

        $this->actingAs($buyer, 'buyer');
        $html = $this->get(route('buyer.cart.index'))->assertOk()->getContent();
        $mini = $this->getJson(route('buyer.cart.mini'))->assertOk()->assertJsonPath('subtotal_centavos', 99200)->json('items');
        foreach (['Black' => 12025, 'White' => 25050] as $color => $price) {
            $row = $this->row($html, $lines[$color]->id);
            $this->assertStringContainsString('/storage/cart/'.$color.'.jpg', $row);
            $this->assertStringContainsString('data-unit-centavos="'.$price.'"', $row);
            $item = collect($mini)->firstWhere('id', $lines[$color]->id);
            $this->assertSame($color, $item['variant']);
            $this->assertSame($price, $item['price_centavos']);
            $this->assertSame('/storage/cart/'.$color.'.jpg', $item['image_url']);
        }

        $this->patchJson(route('buyer.cart.update', $lines['White']), ['quantity' => 2])->assertOk()->assertJsonPath('quantity', 2);
        $this->getJson(route('buyer.cart.mini'))->assertJsonPath('subtotal_centavos', 74150);
        $this->assertSame(2, $lines['Black']->fresh()->quantity);
        $this->deleteJson(route('buyer.cart.destroy', $lines['Black']))->assertOk();
        $this->getJson(route('buyer.cart.mini'))->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $lines['White']->id)->assertJsonPath('subtotal_centavos', 50100);
        $this->assertSame(20, $variants['White']->fresh()->stock_quantity);
    }
}
