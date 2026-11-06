<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutItemDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $options, string $name = 'Classic Cotton Shirt'): Product
    {
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'name' => $name, 'price' => '350.00', 'stock_quantity' => 20,
        ]);
        if ($options !== []) {
            app(VariantSyncService::class)->sync($product,
                array_map(fn ($name, $value) => ['name' => $name, 'values' => [$value]], array_keys($options), array_values($options)),
                [['values' => array_values($options), 'price' => '350.00', 'stock_quantity' => 20, 'is_available' => true]], true);
        }

        return $product;
    }

    private function row(string $html, int $id): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new \DOMXPath($document))->query('//*[@data-cart-item-id="'.$id.'"]');
        $this->assertCount(1, $nodes);

        return $document->saveHTML($nodes->item(0));
    }

    public static function displays(): array
    {
        $cases = [];
        foreach (['cart', 'buy_now'] as $flow) {
            $cases[$flow.'_color_size'] = [$flow, ['Color' => 'Black', 'Size' => 'Large'], 'Color: Black | Size: Large'];
            $cases[$flow.'_color'] = [$flow, ['Color' => 'Black'], 'Color: Black'];
            $cases[$flow.'_size'] = [$flow, ['Size' => 'Large'], 'Size: Large'];
            $cases[$flow.'_optionless'] = [$flow, [], ''];
        }

        return $cases;
    }

    #[DataProvider('displays')]
    public function test_checkout_renders_selected_product_options_and_money(string $flow, array $options, string $label): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product($options);
        $variant = $product->variants()->sole();
        $this->actingAs($buyer, 'buyer');
        if ($flow === 'buy_now') {
            $redirect = $this->postJson(route('buyer.checkout.buy-now'), [
                'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2,
            ])->assertOk()->json('redirect');
            $line = $buyer->cartItems()->sole();
        } else {
            $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
            $redirect = route('buyer.checkout.index', ['items' => [$line->id]]);
        }

        $response = $this->get($redirect)->assertOk();
        $row = $this->row($response->getContent(), $line->id);
        $this->assertStringContainsString('Classic Cotton Shirt', $row);
        $this->assertStringContainsString('Qty: 2', $row);
        $this->assertStringContainsString('Unit Price: ₱350.00', $row);
        $this->assertStringContainsString('Subtotal: ₱700.00', $row);
        if ($label !== '') {
            $this->assertStringContainsString($label, $row);
        } else {
            $this->assertStringNotContainsString('Color:', $row);
            $this->assertStringNotContainsString('Size:', $row);
            $this->assertStringNotContainsString('Default', $row);
        }
        if (count($options) < 2) {
            $this->assertStringNotContainsString(' | ', $row);
        }
    }

    public function test_multiple_variants_and_sellers_keep_their_own_labels_and_prices(): void
    {
        $buyer = Buyer::factory()->create();
        $shirt = $this->product(['Color' => 'Black', 'Size' => 'Large']);
        app(VariantSyncService::class)->sync($shirt,
            [['name' => 'Color', 'values' => ['Black', 'White']], ['name' => 'Size', 'values' => ['Large']]],
            [
                ['values' => ['Black', 'Large'], 'price' => '350.00', 'stock_quantity' => 20, 'is_available' => true],
                ['values' => ['White', 'Large'], 'price' => '400.00', 'stock_quantity' => 20, 'is_available' => true],
            ], true);
        $tote = $this->product([], 'Cotton Tote');
        $variants = $shirt->variants()->with('optionValues')->get()->sortBy(fn ($variant) => $variant->label())->values();
        $black = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $shirt->id, 'variant_id' => $variants[0]->id, 'quantity' => 2]);
        $white = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $shirt->id, 'variant_id' => $variants[1]->id, 'quantity' => 3]);
        $bag = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $tote->id, 'variant_id' => $tote->variants()->sole()->id, 'quantity' => 1]);

        $html = $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk()
            ->assertSee($shirt->seller->business_name)->assertSee($tote->seller->business_name)->getContent();
        foreach ([[$black, 'Classic Cotton Shirt', 'Color: Black | Size: Large', '350.00', '700.00', 2], [$white, 'Classic Cotton Shirt', 'Color: White | Size: Large', '400.00', '1,200.00', 3], [$bag, 'Cotton Tote', '', '350.00', '350.00', 1]] as [$line, $name, $label, $price, $subtotal, $quantity]) {
            $row = $this->row($html, $line->id);
            foreach ([$name, 'Qty: '.$quantity, 'Unit Price: ₱'.$price, 'Subtotal: ₱'.$subtotal] as $text) {
                $this->assertStringContainsString($text, $row);
            }
            if ($label !== '') {
                $this->assertStringContainsString($label, $row);
            }
        }
    }

    public static function checkoutFlows(): array
    {
        return [['cart'], ['buy_now']];
    }

    #[DataProvider('checkoutFlows')]
    public function test_selected_checkout_line_keeps_its_options_and_excludes_other_variants_and_sellers(string $flow): void
    {
        $buyer = Buyer::factory()->create();
        $shirt = $this->product(['Color' => 'Black', 'Size' => 'Large']);
        app(VariantSyncService::class)->sync($shirt,
            [['name' => 'Color', 'values' => ['Black', 'White']], ['name' => 'Size', 'values' => ['Large']]],
            [
                ['values' => ['Black', 'Large'], 'price' => '350.00', 'stock_quantity' => 20, 'is_available' => true],
                ['values' => ['White', 'Large'], 'price' => '400.00', 'stock_quantity' => 20, 'is_available' => true],
            ], true);
        $variants = $shirt->variants()->with('optionValues')->get()->keyBy(fn ($variant) => $variant->label());
        $otherVariant = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $shirt->id, 'variant_id' => $variants['Black / Large']->id, 'quantity' => 3]);
        $tote = $this->product([], 'Unselected Seller Tote');
        $otherSeller = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $tote->id, 'variant_id' => $tote->variants()->sole()->id, 'quantity' => 1]);
        $this->actingAs($buyer, 'buyer');
        $payload = ['product_id' => $shirt->id, 'variant_id' => $variants['White / Large']->id, 'quantity' => 2];

        if ($flow === 'buy_now') {
            $url = $this->postJson(route('buyer.checkout.buy-now'), $payload)->assertOk()->json('redirect');
            $line = $buyer->cartItems()->where('variant_id', $variants['White / Large']->id)->sole();
        } else {
            $this->postJson(route('buyer.cart.add'), $payload)->assertOk();
            $line = $buyer->cartItems()->where('variant_id', $variants['White / Large']->id)->sole();
            $url = route('buyer.checkout.index', ['items' => [$line->id]]);
        }
        $response = $this->get($url)->assertOk();

        $row = $this->row($response->getContent(), $line->id);
        $this->assertStringContainsString('Classic Cotton Shirt', $row);
        $this->assertStringContainsString('Color: White | Size: Large', $row);
        $this->assertStringNotContainsString('Color: Black', $row);
        $this->assertStringContainsString('Qty: 2', $row);
        $response->assertDontSee('data-cart-item-id="'.$otherVariant->id.'"', false)
            ->assertDontSee('data-cart-item-id="'.$otherSeller->id.'"', false)
            ->assertDontSee('Unselected Seller Tote');
        $this->assertSame(3, $otherVariant->fresh()->quantity);
        $this->assertSame(1, $otherSeller->fresh()->quantity);
    }

    public static function unavailable(): array
    {
        return [['disabled'], ['sold_out'], ['foreign'], ['missing_variant'], ['legacy_ambiguous'], ['deleted_product']];
    }

    #[DataProvider('unavailable')]
    public function test_invalid_cart_selection_is_not_replaced_by_another_variant(string $case): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product(['Color' => 'Black']);
        $variant = $product->variants()->sole();
        $alternative = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '999.00', 'stock_quantity' => 10]);
        $white = $variant->optionValues->first()->option->values()->create(['value' => 'White', 'sort_order' => 1]);
        $alternative->optionValues()->attach($white->id);
        if ($case === 'disabled') {
            $variant->update(['is_available' => false]);
        }
        if ($case === 'sold_out') {
            $variant->update(['stock_quantity' => 0]);
        }
        if ($case === 'foreign') {
            $variant = $this->product([])->variants()->sole();
        }
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $case === 'legacy_ambiguous' ? null : $variant->id, 'quantity' => 2]);
        if ($case === 'missing_variant') {
            $variant->delete();
        }
        if ($case === 'deleted_product') {
            $product->delete();
        }

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index', ['items' => [$line->id]]))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        if ($case === 'missing_variant') {
            $this->assertDatabaseMissing('cart_items', ['id' => $line->id]);
        } else {
            $this->assertSame(2, $line->fresh()->quantity);
        }
    }

    public static function invalidBuyNow(): array
    {
        return [['disabled'], ['sold_out'], ['foreign'], ['missing'], ['omitted']];
    }

    #[DataProvider('invalidBuyNow')]
    public function test_buy_now_rejects_invalid_selection_without_creating_a_fallback_line(string $case): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product(['Color' => 'Black', 'Size' => 'Large']);
        $variant = $product->variants()->sole();
        if ($case === 'disabled') {
            $variant->update(['is_available' => false]);
        }
        if ($case === 'sold_out') {
            $variant->update(['stock_quantity' => 0]);
        }
        if ($case === 'foreign') {
            $variant = $this->product([])->variants()->sole();
        }
        $variantId = $variant->id;
        if ($case === 'missing') {
            $variant->delete();
        }

        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.checkout.buy-now'), [
            'product_id' => $product->id,
            'variant_id' => $case === 'omitted' ? null : $variantId,
            'quantity' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unavailable_product_is_explained_while_valid_legacy_optionless_line_still_renders(): void
    {
        $buyer = Buyer::factory()->create();
        $valid = $this->product([], 'Available Tote');
        $removed = $this->product([], 'Removed Shirt');
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $valid->id, 'variant_id' => null, 'quantity' => 2]);
        $unavailable = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $removed->id, 'variant_id' => $removed->variants()->sole()->id]);
        $removed->delete();

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk()
            ->assertSee('Some items in your cart are no longer available and have been excluded from this order.');
        $row = $this->row($response->getContent(), $line->id);
        $this->assertStringContainsString('Available Tote', $row);
        $this->assertStringNotContainsString('Default', $row);
        $this->assertStringContainsString('Subtotal: ₱700.00', $row);
        $response->assertDontSee('data-cart-item-id="'.$unavailable->id.'"', false);
    }

    public function test_checkout_escapes_product_names_and_option_values(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product(['Color' => '<script>red</script>'], '<script>shirt</script>');
        CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $product->variants()->sole()->id]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk()
            ->assertSee('<script>shirt</script>')->assertSee('Color: <script>red</script>')
            ->assertDontSee('<script>shirt</script>', false)->assertDontSee('<script>red</script>', false);
    }
}
