<?php

namespace Tests\Feature\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Z-007: placing an order must consume the voucher, otherwise a
 * single-use voucher (usage_limit = 1) stays redeemable forever because
 * Voucher::rejectionReason() compares against times_used.
 */
class VoucherUsageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_placing_an_order_increments_the_vouchers_times_used(): void
    {
        $buyer = Buyer::factory()->create([
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Diliman',
            'street' => 'Maple Street',
        ]);

        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create()->id,
            'price' => 100.00,
            'stock_quantity' => 5,
        ]);

        $cartItem = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $voucher = Voucher::factory()->fixed(1000)->create([
            'code' => 'ONCEONLY',
            'usage_limit' => 1,
            'times_used' => 0,
        ]);

        $this->actingAs($buyer, 'buyer');

        session([
            CheckoutController::SESSION_KEY => [$cartItem->id],
            'cart_voucher' => 'ONCEONLY',
        ]);

        $this->post(route('buyer.checkout.store'), ['checkout_token' => CheckoutToken::issue($buyer->id)->token])->assertRedirect();

        $this->assertSame(1, $voucher->fresh()->times_used, 'The order must count against the voucher usage limit.');

        // Having been consumed, the same voucher can no longer be applied.
        $this->assertSame(
            'This voucher has reached its usage limit.',
            $voucher->fresh()->rejectionReason(10000),
        );
    }
}
