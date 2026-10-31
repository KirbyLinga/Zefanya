<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CartLimitsAndPersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_add_and_merge_explain_the_available_quantity(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.cart.add'), [
            'product_id' => $product->id, 'quantity' => 15,
        ])->assertOk()->assertJsonPath('quantity_warning', null);

        $this->postJson(route('buyer.cart.add'), [
            'product_id' => $product->id, 'quantity' => 10,
        ])->assertOk()->assertJsonPath('quantity', 20)
            ->assertJsonPath('quantity_limit', 20)
            ->assertJsonPath('quantity_warning', 'Only 20 available.');

        $this->assertSame(1, $buyer->cartItems()->count());
        $this->assertSame(20, $buyer->cartItems()->first()->quantity);
        $this->assertSame(20, $product->fresh()->stock_quantity);
    }

    public function test_update_returns_the_current_stock_limit_and_warning(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 3]);
        $item = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer, 'buyer')->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 25])
            ->assertOk()->assertJsonPath('quantity', 3)->assertJsonPath('quantity_limit', 3)
            ->assertJsonPath('quantity_warning', 'Only 3 available.');
        $this->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 2])
            ->assertOk()->assertJsonPath('quantity_warning', null);
    }

    public function test_unavailable_lines_cannot_be_selected_by_a_forged_request(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->draft()->create();
        $item = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);

        $this->actingAs($buyer, 'buyer')->getJson(route('buyer.cart.summary', ['selected_ids' => [$item->id]]))
            ->assertOk()->assertJsonPath('selected_lines', 0)->assertJsonPath('total_raw', 0);
    }

    public function test_a_fresh_authenticated_session_loads_the_same_account_cart(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.cart.add'), [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertOk();
        $id = $buyer->cartItems()->first()->id;

        auth('buyer')->logout();
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        $this->actingAs($buyer->fresh(), 'buyer')->getJson(route('buyer.cart.summary'))
            ->assertOk()->assertJsonPath('selected_lines', 1);
        $this->assertSame($id, $buyer->cartItems()->first()->id);
        $this->assertSame(4, $buyer->cartItems()->first()->quantity);

        $this->actingAs(Buyer::factory()->create(), 'buyer')->getJson(route('buyer.cart.summary'))
            ->assertOk()->assertJsonPath('selected_lines', 0);
    }
}
