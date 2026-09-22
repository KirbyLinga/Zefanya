<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── add ─────────────────────────────────────────────────────────────────

    public function test_add_merges_quantity_for_the_same_product(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 25.00]);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('cart_count', 2);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('cart_count', 5);

        $this->assertSame(1, CartItem::where('buyer_id', $buyer->id)->count());
        $this->assertSame(5, CartItem::where('buyer_id', $buyer->id)->value('quantity'));
    }

    public function test_add_caps_the_quantity_at_available_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 4]);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 50])
            ->assertOk()
            ->assertJsonPath('cart_count', 4);

        $this->assertSame(4, CartItem::where('buyer_id', $buyer->id)->value('quantity'));
    }

    public function test_add_rejects_products_that_are_not_purchaseable(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();

        $draft = Product::factory()->draft()->create(['seller_id' => $seller->id]);
        $outOfStock = Product::factory()->outOfStock()->create(['seller_id' => $seller->id]);
        $deleted = Product::factory()->create(['seller_id' => $seller->id]);
        $deleted->delete();

        foreach ([$draft->id, $outOfStock->id, $deleted->id, 999999] as $productId) {
            $this->actingAs($buyer, 'buyer')
                ->postJson(route('buyer.cart.add'), ['product_id' => $productId, 'quantity' => 1])
                ->assertStatus(422);
        }

        // An out-of-stock product must never produce a quantity-0 line.
        $this->assertSame(0, CartItem::where('buyer_id', $buyer->id)->count());
    }

    public function test_add_requires_a_positive_quantity(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 0])
            ->assertStatus(422);
    }

    // ── update ──────────────────────────────────────────────────────────────

    public function test_update_changes_the_quantity_and_returns_the_new_line_total(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 10.00, 'stock_quantity' => 10]);
        $item = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('line_total', '₱30.00')
            ->assertJsonPath('quantity', 3)
            ->assertJsonPath('subtotal', '₱30.00')
            ->assertJsonPath('total', '₱30.00')
            ->assertJsonPath('selected_qty', 3)
            ->assertJsonPath('cart_count', 3);

        $this->assertSame(3, $item->fresh()->quantity);
    }

    public function test_update_rejects_quantity_below_one_and_caps_above_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $item = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 0])
            ->assertStatus(422);

        $this->actingAs($buyer, 'buyer')
            ->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 99])
            ->assertOk()
            ->assertJsonPath('quantity', 5);

        $this->assertSame(5, $item->fresh()->quantity);
    }

    public function test_update_rejects_an_item_whose_product_is_no_longer_available(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $item = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        $product->delete();

        $this->actingAs($buyer, 'buyer')
            ->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 2])
            ->assertStatus(422);

        $this->assertSame(1, $item->fresh()->quantity);
    }

    // ── destroy + bulkDestroy ───────────────────────────────────────────────

    public function test_destroy_removes_the_line_and_reports_an_empty_cart(): void
    {
        $buyer = Buyer::factory()->create();
        $item = CartItem::factory()->create(['buyer_id' => $buyer->id]);

        $this->actingAs($buyer, 'buyer')
            ->deleteJson(route('buyer.cart.destroy', $item->id))
            ->assertOk()
            ->assertJsonPath('removed_id', $item->id)
            ->assertJsonPath('is_empty', true)
            ->assertJsonPath('cart_count', 0);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_bulk_destroy_removes_only_the_given_ids(): void
    {
        $buyer = Buyer::factory()->create();
        $keep = CartItem::factory()->create(['buyer_id' => $buyer->id]);
        $a = CartItem::factory()->create(['buyer_id' => $buyer->id]);
        $b = CartItem::factory()->create(['buyer_id' => $buyer->id]);

        $this->actingAs($buyer, 'buyer')
            ->deleteJson(route('buyer.cart.bulk-destroy'), ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('removed_ids', [$a->id, $b->id])
            ->assertJsonPath('is_empty', false);

        $this->assertDatabaseMissing('cart_items', ['id' => $a->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $b->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $keep->id]);
    }

    // ── Ownership ───────────────────────────────────────────────────────────

    public function test_a_buyer_cannot_update_or_delete_another_buyers_cart_item(): void
    {
        $owner = Buyer::factory()->create();
        $intruder = Buyer::factory()->create();
        $item = CartItem::factory()->create(['buyer_id' => $owner->id, 'quantity' => 2]);

        $this->actingAs($intruder, 'buyer')
            ->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 5])
            ->assertStatus(404);

        $this->actingAs($intruder, 'buyer')
            ->deleteJson(route('buyer.cart.destroy', $item->id))
            ->assertStatus(404);

        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_bulk_destroy_is_forbidden_when_any_id_belongs_to_another_buyer(): void
    {
        $owner = Buyer::factory()->create();
        $intruder = Buyer::factory()->create();

        $ownItem = CartItem::factory()->create(['buyer_id' => $intruder->id]);
        $foreignItem = CartItem::factory()->create(['buyer_id' => $owner->id]);

        $this->actingAs($intruder, 'buyer')
            ->deleteJson(route('buyer.cart.bulk-destroy'), ['ids' => [$ownItem->id, $foreignItem->id]])
            ->assertStatus(403);

        // Nothing is deleted — no silent partial delete.
        $this->assertDatabaseHas('cart_items', ['id' => $ownItem->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $foreignItem->id]);
    }

    // ── Guests ──────────────────────────────────────────────────────────────

    public function test_guests_get_a_401_json_response_from_every_cart_endpoint(): void
    {
        $item = CartItem::factory()->create();

        $this->postJson(route('buyer.cart.add'), ['product_id' => 1, 'quantity' => 1])
            ->assertStatus(401);

        $this->getJson(route('buyer.cart.summary'))->assertStatus(401);
        $this->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 2])->assertStatus(401);
        $this->deleteJson(route('buyer.cart.destroy', $item->id))->assertStatus(401);
        $this->deleteJson(route('buyer.cart.bulk-destroy'), ['ids' => [$item->id]])->assertStatus(401);
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => 'X'])->assertStatus(401);
        $this->deleteJson(route('buyer.cart.voucher.remove'))->assertStatus(401);
    }

    // ── Vouchers ────────────────────────────────────────────────────────────

    public function test_a_voucher_can_be_applied_and_removed(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 300.00, 'stock_quantity' => 5]);
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        Voucher::factory()->fixed(20000)->create(['code' => 'ZEFANYANEW']);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.voucher.apply'), ['code' => 'zefanyanew'])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('has_voucher', true)
            ->assertJsonPath('voucher_code', 'ZEFANYANEW')
            ->assertJsonPath('discount', '-₱200.00')
            ->assertJsonPath('total', '₱100.00');

        $this->assertSame('ZEFANYANEW', session('cart_voucher'));

        $this->actingAs($buyer, 'buyer')
            ->deleteJson(route('buyer.cart.voucher.remove'))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('has_voucher', false)
            ->assertJsonPath('total', '₱300.00');

        $this->assertNull(session('cart_voucher'));
    }

    public function test_unknown_inactive_expired_and_below_minimum_vouchers_are_rejected(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 300.00, 'stock_quantity' => 5]);
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        Voucher::factory()->inactive()->create(['code' => 'OFFNOW']);
        Voucher::factory()->expired()->create(['code' => 'OLDNEWS']);
        Voucher::factory()->fixed(5000)->minSpend(100000)->create(['code' => 'BIGSPEND']);

        foreach (['NOPE', 'OFFNOW', 'OLDNEWS', 'BIGSPEND'] as $code) {
            $this->actingAs($buyer, 'buyer')
                ->postJson(route('buyer.cart.voucher.apply'), ['code' => $code])
                ->assertStatus(422)
                ->assertJsonStructure(['error']);
        }

        $this->assertNull(session('cart_voucher'));
    }

    public function test_a_voucher_is_dropped_when_the_selected_subtotal_falls_below_its_minimum(): void
    {
        $buyer = Buyer::factory()->create();

        $expensive = Product::factory()->create(['price' => 300.00, 'stock_quantity' => 5]);
        $cheap = Product::factory()->create(['price' => 100.00, 'stock_quantity' => 5]);

        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $expensive->id,
        ]);
        $cheapItem = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $cheap->id,
        ]);

        Voucher::factory()->fixed(5000)->minSpend(30000)->create(['code' => 'MIN300']);

        // ₱400 subtotal → above the ₱300 minimum, so it applies.
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.voucher.apply'), ['code' => 'MIN300'])
            ->assertOk()
            ->assertJsonPath('has_voucher', true);

        // Narrow the selection to the ₱100 line → below the minimum, so the
        // voucher is dropped and the reason is reported.
        $url = route('buyer.cart.summary').'?selected_ids[]='.$cheapItem->id;

        $this->actingAs($buyer, 'buyer')
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('has_voucher', false)
            ->assertJsonPath('subtotal', '₱100.00')
            ->assertJsonPath('total', '₱100.00');

        $this->assertNull(session('cart_voucher'));
    }

    // ── Selection semantics ─────────────────────────────────────────────────

    public function test_an_empty_selection_reports_zero_totals(): void
    {
        $buyer = Buyer::factory()->create();
        CartItem::factory()->create(['buyer_id' => $buyer->id, 'quantity' => 2]);

        // `selected_ids` present but empty → "nothing selected".
        // `?selected_ids[]=` is exactly what the page JS sends in that case.
        $this->actingAs($buyer, 'buyer')
            ->getJson(route('buyer.cart.summary').'?selected_ids[]=')
            ->assertOk()
            ->assertJsonPath('subtotal', '₱0.00')
            ->assertJsonPath('total', '₱0.00')
            ->assertJsonPath('selected_qty', 0)
            ->assertJsonPath('selected_lines', 0)
            ->assertJsonPath('available_lines', 1);
    }

    public function test_an_absent_selection_covers_every_available_item(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 50.00, 'stock_quantity' => 5]);
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->getJson(route('buyer.cart.summary'))
            ->assertOk()
            ->assertJsonPath('subtotal', '₱100.00')
            ->assertJsonPath('selected_qty', 2);
    }

    // ── Page ────────────────────────────────────────────────────────────────

    public function test_the_cart_page_groups_items_by_seller_and_excludes_unavailable_lines(): void
    {
        $buyer = Buyer::factory()->create();

        $northwind = Seller::factory()->create(['business_name' => 'Northwind Goods']);
        $hearth = Seller::factory()->create(['business_name' => 'Hearth and Nest']);

        $headphones = Product::factory()->create([
            'seller_id' => $northwind->id,
            'name' => 'Wireless Noise Cancelling Headphones',
            'price' => 3499.00,
            'stock_quantity' => 10,
        ]);

        $mug = Product::factory()->create([
            'seller_id' => $hearth->id,
            'name' => 'Minimalist Ceramic Coffee Mug Set',
            'price' => 649.00,
            'stock_quantity' => 10,
        ]);

        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $headphones->id,
            'quantity' => 1,
        ]);
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $mug->id,
            'quantity' => 2,
        ]);

        // An out-of-stock line: dimmed, excluded from the totals, still visible.
        $soldOut = Product::factory()->outOfStock()->create([
            'seller_id' => $northwind->id,
            'name' => 'Sold Out Camera Bag',
        ]);
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $soldOut->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'));

        $response->assertOk();
        $response->assertSee('Your Shopping Cart');
        $response->assertSee('Northwind Goods');
        $response->assertSee('Hearth and Nest');
        $response->assertSee('Wireless Noise Cancelling Headphones');
        $response->assertSee('Minimalist Ceramic Coffee Mug Set');
        $response->assertSee('No longer available');
        $response->assertSee('Set of 2 (₱649.00 each)');

        // ₱3,499.00 + 2 × ₱649.00 = ₱4,797.00 — the sold-out line is excluded.
        $response->assertSee('4,797.00');
        $response->assertSee('Select all');
        $response->assertSee('Secure 256-bit SSL encrypted checkout');
    }

    public function test_the_cart_page_shows_the_empty_state_when_there_are_no_items(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.cart.index'))
            ->assertOk()
            ->assertSee('Your cart is empty')
            ->assertDontSee('Select all');
    }

    public function test_the_navbar_badge_shows_the_real_cart_quantity(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10]);

        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'));

        $response->assertOk();
        $response->assertSee('id="cartCount"', false);
        $response->assertSee('>3</span>', false);
    }

    // ── Checkout hand-off ───────────────────────────────────────────────────

    public function test_checkout_accepts_only_the_buyers_own_selected_items(): void
    {
        $buyer = Buyer::factory()->create();
        $otherBuyer = Buyer::factory()->create();

        $mine = CartItem::factory()->create(['buyer_id' => $buyer->id]);
        $alsoMine = CartItem::factory()->create(['buyer_id' => $buyer->id]);
        $theirs = CartItem::factory()->create(['buyer_id' => $otherBuyer->id]);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.checkout.index', ['items' => [$mine->id, $theirs->id]]))
            ->assertOk();

        $this->assertSame([$mine->id], session('checkout_item_ids'));

        // No `items` at all → the whole available cart.
        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$mine->id, $alsoMine->id],
            session('checkout_item_ids'),
        );
    }
}
