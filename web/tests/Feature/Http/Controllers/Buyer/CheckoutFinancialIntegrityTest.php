<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CheckoutFinancialIntegrityTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function line(Buyer $buyer, string $price = '100.00'): CartItem
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'price' => $price, 'stock_quantity' => 10]);

        return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $product->variants()->sole()->id, 'quantity' => 1]);
    }

    private function prepareCheckout(Buyer $buyer, array $lines, ?string $voucher = null): CheckoutToken
    {
        $sellers = collect($lines)
            ->map(fn (CartItem $line) => $line->product->seller)
            ->unique('id')
            ->values();
        $this->bootstrapCheckoutCarriers($buyer, $sellers);

        $this->actingAs($buyer, 'buyer');
        $token = CheckoutToken::issue($buyer->id);
        session([CheckoutController::SESSION_KEY => array_map(fn ($line) => $line->id, $lines), CheckoutController::TOKEN_KEY => $token->token, 'cart_voucher' => $voucher]);

        return $token;
    }

    /** @return array<string, mixed> */
    private function storePayload(CheckoutToken $token, array $lines, array $extra = []): array
    {
        $sellers = collect($lines)
            ->map(fn (CartItem $line) => $line->product->seller)
            ->unique('id');

        return [
            'checkout_token' => $token->token,
            ...$this->checkoutCarrierPayload($sellers),
            ...$extra,
        ];
    }

    public static function changedPrices(): array
    {
        return ['increase' => ['125.99'], 'decrease below voucher minimum' => ['99.99']];
    }

    public static function percentageDiscountBoundaries(): array
    {
        return [['1.04', 10, 10], ['1.05', 10, 11], ['1.06', 10, 11], ['9.99', 15, 150], ['0.01', 50, 1]];
    }

    #[DataProvider('percentageDiscountBoundaries')]
    public function test_cart_checkout_order_and_cod_share_the_same_half_up_discount(string $price, int $percent, int $discount): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer, $price);
        Voucher::factory()->percent($percent)->create(['code' => 'HALFUP', 'funding_source' => Voucher::FUNDING_SELLER]);
        $this->prepareCheckout($buyer, [$line], 'HALFUP');
        $page = $this->get(route('buyer.checkout.index'))->assertOk();
        $summary = $page->viewData('summary');
        $this->assertSame($discount, $summary->discountCentavos);
        $token = CheckoutToken::query()->where('token', $page->viewData('checkoutToken'))->sole();

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::query()->sole();
        $sellerOrder = $order->sellerOrders()->sole();
        $payment = Payment::query()->sole();
        $this->assertSame($discount, $order->discount_minor);
        $this->assertSame($discount, $sellerOrder->voucher_discount_minor);
        $this->assertSame($summary->totalCentavos + 4900, $order->total_minor);
        $this->assertSame($order->total_minor, $payment->amount_minor);
        $this->assertSame(PaymentMethod::Cod, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_multi_store_percentage_rounding_preserves_the_order_and_cod_totals(): void
    {
        $buyer = Buyer::factory()->create();
        $first = $this->line($buyer, '0.05');
        $second = $this->line($buyer, '0.10');
        Voucher::factory()->percent(10)->create(['code' => 'TWOCENTS', 'funding_source' => Voucher::FUNDING_SELLER]);
        $token = $this->prepareCheckout($buyer, [$first, $second], 'TWOCENTS');

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$first, $second]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(15, $order->subtotal_minor);
        $this->assertSame(2, $order->discount_minor);
        $this->assertSame(2, (int) $order->sellerOrders()->sum('voucher_discount_minor'));
        $this->assertSame(9813, $order->total_minor);
        $this->assertSame(9813, (int) Payment::query()->sum('amount_minor'));
    }

    public function test_checkout_only_displays_cash_on_delivery(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer);
        $this->prepareCheckout($buyer, [$line]);

        $this->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Cash on Delivery (COD)')
            ->assertDontSee('GCash')
            ->assertDontSee('Bank transfer')
            ->assertDontSee('Credit / debit card')
            ->assertDontSee('not yet integrated');
    }

    public static function submittedPaymentMethods(): array
    {
        return [
            'omitted' => [null],
            'cod' => ['cod'],
            'card' => ['card'],
            'e-wallet' => ['gcash'],
            'bank transfer' => ['bank_transfer'],
            'malformed' => [['card']],
        ];
    }

    #[DataProvider('submittedPaymentMethods')]
    public function test_checkout_always_creates_cod_orders_ready_to_ship(mixed $method): void
    {
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer);
        $token = $this->prepareCheckout($buyer, [$line]);
        $extra = $method === null ? [] : ['payment_method' => $method];

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line], $extra))
            ->assertRedirect(route('buyer.checkout.success', Order::query()->sole()))
            ->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame('to_ship', $order->sellerOrders()->sole()->status->bucket());
        $payment = Payment::query()->sole();
        $this->assertSame(PaymentMethod::Cod, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $this->get(route('buyer.orders.index', ['tab' => 'to_ship']))
            ->assertOk()
            ->assertSee($order->reference)
            ->assertDontSee('To Pay');
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

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line]))
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
        if ($price !== '125.99') {
            // The lower price no longer meets the voucher's minimum spend, so
            // losing the voucher is one more thing to confirm before ordering.
            $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line]))
                ->assertSessionHas('error', fn ($message) => str_contains($message, 'minimum spend'));
            $this->assertDatabaseCount('orders', 0);
        }
        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line]))->assertRedirect();
        $order = Order::query()->sole();
        $minor = $price === '125.99' ? 12599 : 9999;
        $discount = $price === '125.99' ? 1000 : 0;
        $this->assertSame($minor, $order->subtotal_minor);
        $this->assertSame($discount, $order->discount_minor);
        $this->assertSame($minor - $discount + 4900, $order->total_minor);
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

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$first, $second], ['price' => 999, 'total_minor' => 999999]))
            ->assertSessionMissing('error')->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(5, $order->subtotal_minor);
        $this->assertSame(1, $order->discount_minor);
        $this->assertSame(9804, $order->total_minor);
        $this->assertSame(5, (int) $order->sellerOrders()->sum('subtotal_minor'));
        $this->assertSame([3, 2], OrderItem::query()->orderBy('id')->pluck('price_minor')->all());
        $this->assertSame([4902, 4902], Payment::query()->orderBy('id')->pluck('amount_minor')->all());
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

        $this->post(route('buyer.checkout.store'), $this->storePayload($token, [$line]))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'voucher changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertFalse($token->fresh()->used);
        $this->assertSame(0, $voucher->fresh()->times_used);
    }
}
