<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CheckoutSellerEligibilityTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active', 'stock_quantity' => 5]);
        $variant = $product->variants()->sole();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $this->actingAs($buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => [$line->id]]);

        return [$buyer, $seller, $product, $variant, $line];
    }

    public static function ineligibleStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['suspended', 'rejected', 'pending', 'unverified', 'inactive', 'archived']);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_ineligible_seller_blocks_all_purchase_entry_points_and_preserves_cart(string $status): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        $seller->forceFill($status === 'archived' ? ['archived_at' => now()] : ['status' => $status])->save();
        $this->assertFalse($line->fresh()->isAvailable());
        $this->get(route('buyer.products.show', $product))->assertNotFound();
        $data = ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1];
        $this->postJson(route('buyer.cart.add'), $data)->assertUnprocessable();
        $this->postJson(route('buyer.checkout.buy-now'), $data)->assertUnprocessable();
        $this->get(route('buyer.cart.index'))->assertOk()->assertSee('No longer available');
        $token = CheckoutToken::issue($buyer->id);
        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertFalse($token->fresh()->used);
        $this->assertModelExists($line);
    }

    public function test_suspension_between_precheck_and_lock_rolls_back_claim_and_order(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        $token = CheckoutToken::issue($buyer->id);
        $changed = false;
        Seller::retrieved(function ($row) use ($seller, &$changed): void {
            if (! $changed && $row->id === $seller->id) {
                $changed = true;
                DB::table('sellers')->where('id', $row->id)->update(['status' => 'suspended']);
            }
        });
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token,
            ...$this->checkoutCarrierPayload([$seller]),
        ])
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'seller'));
        $this->assertTrue($changed);
        $this->assertFalse($token->fresh()->used);
        foreach (['orders', 'seller_orders', 'order_items', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertModelExists($line);
    }

    public function test_approved_seller_checkout_succeeds_and_history_survives_suspension(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        $this->get(route('buyer.products.show', $product))->assertOk();
        $this->assertTrue($line->fresh()->isAvailable());
        $token = CheckoutToken::issue($buyer->id);
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token,
            ...$this->checkoutCarrierPayload([$seller]),
        ])->assertSessionMissing('error');
        $order = Order::query()->sole();
        $seller->update(['status' => 'suspended']);
        $this->get(route('buyer.orders.show', $order))->assertOk()->assertSee($product->name);
        $this->get(route('buyer.checkout.success', $order))->assertOk();
        $this->assertSame(4, $variant->fresh()->stock_quantity);
        $this->assertSame($order->total_minor, $order->sellerOrders()->sole()->payment->amount_minor);
    }
}
