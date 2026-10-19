<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutFinancialIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function line(Buyer $buyer, string $price = '100.00'): CartItem
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'price' => $price, 'stock_quantity' => 10]);

        return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $product->variants()->sole()->id, 'quantity' => 1]);
    }

    private function prepareCheckout(Buyer $buyer, array $lines, ?string $voucher = null): CheckoutToken
    {
        $this->actingAs($buyer, 'buyer');
        $token = CheckoutToken::issue($buyer->id);
        session([CheckoutController::SESSION_KEY => array_map(fn ($line) => $line->id, $lines), CheckoutController::TOKEN_KEY => $token->token, 'cart_voucher' => $voucher]);

        return $token;
    }

    public static function changedPrices(): array
    {
        return ['increase' => ['125.99'], 'decrease below voucher minimum' => ['99.99']];
    }

    #[DataProvider('changedPrices')]
    public function test_price_change_between_cart_read_and_lock_requires_reconfirmation(string $price): void
    {
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer);
        $voucher = Voucher::factory()->fixed(1000)->create(['code' => 'MINIMUM', 'min_spend' => 10000]);
        $token = $this->prepareCheckout($buyer, [$line], $voucher->code);
        $changed = false;
        ProductVariant::retrieved(function ($variant) use ($line, $price, &$changed): void {
            if (! $changed && $variant->id === $line->variant_id) {
                $changed = true;
                DB::table('product_variants')->where('id', $variant->id)->update(['price' => $price]);
            }
        });

        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'price changed'));

        $this->assertTrue($changed);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('seller_orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertFalse($token->fresh()->used);
        $this->assertSame(0, $voucher->fresh()->times_used);
        $this->assertSame(10, $line->variant->stock_quantity);
        $this->assertModelExists($line);

        // The same unconsumed token can succeed after the buyer reviews fresh prices.
        session([CheckoutController::SESSION_KEY => [$line->id]]);
        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])->assertRedirect();
        $order = Order::query()->sole();
        $minor = $price === '125.99' ? 12599 : 9999;
        $discount = $price === '125.99' ? 1000 : 0;
        $this->assertSame($minor, $order->subtotal_minor);
        $this->assertSame($discount, $order->discount_minor);
        $this->assertSame($minor - $discount, $order->total_minor);
        $this->assertSame($minor, OrderItem::query()->sole()->price_minor);
        $this->assertSame($order->total_minor, Payment::query()->sole()->amount_minor);
    }

    public function test_seller_subtotals_discounts_lines_and_cod_payments_reconcile_to_the_centavo(): void
    {
        $buyer = Buyer::factory()->create();
        $first = $this->line($buyer, '0.03');
        $second = $this->line($buyer, '0.02');
        Voucher::factory()->fixed(1)->create(['code' => 'ONECENT']);
        $token = $this->prepareCheckout($buyer, [$first, $second], 'ONECENT');

        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token, 'price' => 999, 'total_minor' => 999999])
            ->assertSessionMissing('error')->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(5, $order->subtotal_minor);
        $this->assertSame(1, $order->discount_minor);
        $this->assertSame(4, $order->total_minor);
        $this->assertSame(5, (int) $order->sellerOrders()->sum('subtotal_minor'));
        $this->assertSame([3, 2], OrderItem::query()->orderBy('id')->pluck('price_minor')->all());
        $this->assertSame([2, 2], Payment::query()->orderBy('id')->pluck('amount_minor')->all());
        $this->assertTrue(Payment::query()->get()->every(fn ($payment) => $payment->method === PaymentMethod::Cod));
        $this->assertSame(2, InventoryTransaction::query()->where('reason', 'checkout')->count());
    }

    public function test_a_voucher_revoked_between_resolution_and_lock_cannot_be_consumed(): void
    {
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer);
        $voucher = Voucher::factory()->fixed(1000)->create(['code' => 'REVOKED']);
        $token = $this->prepareCheckout($buyer, [$line], 'REVOKED');
        $revoked = false;
        Voucher::retrieved(function ($loaded) use ($voucher, &$revoked): void {
            if (! $revoked && $loaded->id === $voucher->id) {
                $revoked = true;
                DB::table('vouchers')->where('id', $voucher->id)->update(['is_active' => false]);
            }
        });

        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token])
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'voucher changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertFalse($token->fresh()->used);
        $this->assertSame(0, $voucher->fresh()->times_used);
    }
}
