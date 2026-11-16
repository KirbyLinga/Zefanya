<?php

namespace Tests\Feature\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Buyer\Wishlist;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WishlistAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(string $name = 'Saved Cotton Shirt', bool $optionless = false): Product
    {
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'name' => $name, 'price' => 250, 'stock_quantity' => $optionless ? 8 : 0,
        ]);
        if (! $optionless) {
            app(VariantSyncService::class)->sync($product,
                [['name' => 'Color', 'values' => ['Black', 'White']]],
                [
                    ['values' => ['Black'], 'price' => 250, 'stock_quantity' => 4, 'is_available' => true],
                    ['values' => ['White'], 'price' => 300, 'stock_quantity' => 4, 'is_available' => true],
                ], true);
        }

        return $product->fresh();
    }

    private function saveFor(Buyer $buyer, Product $product): Wishlist
    {
        return Wishlist::create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
    }

    private function change(Product $product, string $state): void
    {
        match ($state) {
            'inactive' => $product->update(['status' => 'inactive']),
            'suspended' => $product->seller->update(['status' => 'suspended']),
            'pending' => $product->seller->update(['status' => 'pending']),
            'disabled' => $product->variants()->update(['is_available' => false]),
            'sold_out' => $product->variants()->update(['stock_quantity' => 0]),
            'archived' => $product->delete(),
            default => null,
        };
    }

    private function card(string $html, int $productId): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-wishlist-item="'.$productId.'"]')->length);

        return $xpath;
    }

    public static function states(): array
    {
        return [['available'], ['inactive'], ['suspended'], ['pending'], ['disabled'], ['sold_out'], ['archived']];
    }

    #[DataProvider('states')]
    public function test_saved_cards_reuse_catalog_eligibility_and_preserve_records(string $state): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product();
        $saved = $this->saveFor($buyer, $product);
        $this->change($product, $state);
        $canBuy = $state === 'available';

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.wishlist.index'))->assertOk()->assertSee('Saved Cotton Shirt');
        $xpath = $this->card($response->getContent(), $product->id);
        $root = '//*[@data-wishlist-item="'.$product->id.'"]';
        $this->assertSame($canBuy ? 0 : 1, $xpath->query($root.'//button[contains(@class,"add-to-cart-btn")][@disabled]')->length);
        $this->assertSame($canBuy ? 1 : 0, $xpath->query($root.'//a[normalize-space(.)="Add to Cart"]')->length);
        $this->assertSame(1, $xpath->query($root.'//*[@data-wishlist-toggle][@aria-pressed="true"]')->length);
        $response->assertSee($canBuy ? 'in stock' : 'Unavailable');
        $this->assertSame($canBuy, ! $saved->fresh()->product->buyerIsOutOfStock());
        $this->get(route('buyer.products.index'))->assertOk()->assertViewHas('products', fn ($products) => $products->count() === ($canBuy ? 1 : 0));
        $this->assertModelExists($saved);
        $this->assertDatabaseCount('wishlists', 1);
        if (in_array($state, ['inactive', 'suspended', 'pending', 'archived'], true)) {
            $this->assertSame(0, $xpath->query($root.'//a[contains(@href,"/buyer/products/'.$product->id.'")]')->length);
        }
    }

    public static function unavailableStates(): array
    {
        return [['inactive'], ['suspended'], ['pending'], ['disabled'], ['sold_out'], ['archived']];
    }

    #[DataProvider('unavailableStates')]
    public function test_unavailable_saved_product_cannot_bypass_cart_or_checkout_and_recovers(string $state): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product();
        $saved = $this->saveFor($buyer, $product);
        $variant = $product->variants()->first();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $this->change($product, $state);
        $ledgerCount = InventoryTransaction::count();
        $token = CheckoutToken::issue($buyer->id)->token;
        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$line->id]]);

        $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
        $this->postJson(route('buyer.checkout.buy-now'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', $ledgerCount);
        $this->assertSame(1, $line->fresh()->quantity);
        $this->assertModelExists($saved);

        $restored = Product::withTrashed()->findOrFail($product->id);
        if ($restored->trashed()) {
            $restored->restore();
        }
        $restored->update(['status' => 'active']);
        $restored->seller->update(['status' => 'approved']);
        $restored->variants()->update(['is_available' => true, 'stock_quantity' => 4]);
        $response = $this->get(route('buyer.wishlist.index'))->assertOk()->assertSee('in stock');
        $xpath = $this->card($response->getContent(), $product->id);
        $this->assertSame(1, $xpath->query('//*[@data-wishlist-item="'.$product->id.'"]//a[normalize-space(.)="Add to Cart"]')->length);
        $this->assertSame($saved->id, $buyer->wishlist()->sole()->id);
    }

    public function test_optionless_saved_product_uses_the_same_card_and_becomes_disabled_when_sold_out(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product(optionless: true);
        $saved = $this->saveFor($buyer, $product);
        $this->actingAs($buyer, 'buyer');

        $response = $this->get(route('buyer.wishlist.index'))->assertOk()->assertSee('250.00');
        $xpath = $this->card($response->getContent(), $product->id);
        $this->assertSame(1, $xpath->query('//*[@data-wishlist-item="'.$product->id.'"]//button[contains(@class,"add-to-cart-btn")][not(@disabled)]')->length);
        $product->variants()->update(['stock_quantity' => 0]);
        $this->get(route('buyer.wishlist.index'))->assertOk()->assertSee('Unavailable')->assertSee('Out of Stock');
        $this->assertModelExists($saved);
    }

    public function test_wishlist_is_private_and_mutations_only_affect_the_authenticated_buyer(): void
    {
        $owner = Buyer::factory()->create();
        $other = Buyer::factory()->create();
        $private = $this->product('Other Buyer Private Shirt');
        $foreign = $this->saveFor($owner, $private);
        $this->actingAs($other, 'buyer');

        $this->get(route('buyer.wishlist.index', ['buyer_id' => $owner->id]))->assertOk()->assertDontSee('Other Buyer Private Shirt');
        $this->deleteJson(route('buyer.wishlist.destroy', $foreign))->assertNotFound();
        $this->assertModelExists($foreign);
        $this->postJson(route('buyer.wishlist.toggle', $private))->assertOk()->assertJsonPath('isSaved', true)->assertJsonPath('count', 1);
        $this->assertDatabaseCount('wishlists', 2);
        $this->postJson(route('buyer.wishlist.toggle', $private))->assertOk()->assertJsonPath('isSaved', false)->assertJsonPath('count', 0);
        $this->assertModelExists($foreign);
        $this->assertDatabaseCount('wishlists', 1);
    }

    public function test_archived_saved_records_are_counted_and_can_be_explicitly_removed(): void
    {
        $buyer = Buyer::factory()->create();
        $archived = $this->product('Archived Saved Shirt');
        $saved = $this->saveFor($buyer, $archived);
        $archived->delete();
        $available = $this->product('Available Saved Shirt');
        $this->actingAs($buyer, 'buyer');

        $this->postJson(route('buyer.wishlist.toggle', $available))->assertOk()->assertJsonPath('count', 2);
        $response = $this->get(route('buyer.wishlist.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('2', trim($xpath->query('//*[@id="wishlistCount"]')->item(0)->textContent));
        $this->deleteJson(route('buyer.wishlist.destroy', $saved))->assertOk()->assertJsonPath('count', 1);
        $this->assertModelMissing($saved);
        $this->assertSoftDeleted($archived);
    }

    public function test_buyer_can_toggle_an_archived_saved_product_off(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product();
        $saved = $this->saveFor($buyer, $product);
        $product->delete();

        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.wishlist.toggle', $product))->assertOk()->assertJsonPath('isSaved', false)->assertJsonPath('count', 0);

        $this->assertModelMissing($saved);
        $this->assertSoftDeleted($product);
    }

    public function test_guests_cannot_read_or_mutate_wishlists(): void
    {
        $buyer = Buyer::factory()->create();
        $product = $this->product();
        $saved = $this->saveFor($buyer, $product);

        $this->getJson(route('buyer.wishlist.index'))->assertUnauthorized();
        $this->postJson(route('buyer.wishlist.toggle', $product))->assertUnauthorized();
        $this->deleteJson(route('buyer.wishlist.destroy', $saved))->assertUnauthorized();
        $this->assertModelExists($saved);
    }
}
