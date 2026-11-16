<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\PurchaseLimitService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/** Seller-configured purchase limits per product, per Buyer: optional, off by default, enforced on web and API checkout. */
class PurchaseLimitCheckoutTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function shop(): Seller
    {
        return Seller::factory()->create(['status' => 'approved']);
    }

    /**
     * A ₱100.00 product with plenty of stock, with the given limits.
     *
     * @param  array<string, mixed>  $limits
     */
    private function product(Seller $seller, array $limits = []): Product
    {
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active', 'price' => '100.00', 'stock_quantity' => 50]);
        $product->forceFill($limits)->save();

        return $product->fresh();
    }

    private function line(Buyer $buyer, Product $product, int $quantity = 1): CartItem
    {
        return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id,
            'variant_id' => $product->variants()->orderBy('id')->first()->id, 'quantity' => $quantity]);
    }

    /** Place an order through the real web checkout for these cart lines. */
    private function placeWeb(Buyer $buyer, array $sellers, array $lines)
    {
        $this->bootstrapCheckoutCarriers($buyer, $sellers);
        $this->actingAs($buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => array_map(fn (CartItem $l) => $l->id, $lines)]);
        $token = CheckoutToken::issue($buyer->id);

        return [$this->post(route('buyer.checkout.store'), ['checkout_token' => $token->token, ...$this->checkoutCarrierPayload($sellers)]), $token];
    }

    /**
     * An existing COD order of this Buyer at this product's shop holding $units
     * of the product at ₱100.00, plus any other goods worth $otherMinor.
     */
    private function existingOrder(Buyer $buyer, Product $product, OrderStatus $status, PaymentStatus $payment, int $units = 1, int $otherMinor = 0, array $snapshot = []): SellerOrder
    {
        $subtotal = $units * 10000 + $otherMinor;
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $parcel = SellerOrder::factory()->status($status)->create(['order_id' => $order->id, 'seller_id' => $product->seller_id,
            'subtotal_minor' => $subtotal, 'shipping_fee_minor' => 5000] + $snapshot);
        OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id, 'variant_id' => null,
            'product_name' => $product->name, 'quantity' => $units, 'price_minor' => 10000, 'regular_price_minor' => 10000]);
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => $subtotal + 5000,
            'status' => $payment->value, 'paid_at' => $payment === PaymentStatus::Paid ? now() : null]);

        return $parcel;
    }

    private function summaryFor(Buyer $buyer, ?Voucher $voucher = null): CartSummary
    {
        $items = $buyer->cartItems()->with(['product.seller', 'variant'])->get();

        return new CartSummary($items, $voucher);
    }

    private function assertNothingPlaced(CheckoutToken $token, ProductVariant $variant, int $stock): void
    {
        foreach (['orders', 'seller_orders', 'order_items', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($stock, $variant->fresh()->stock_quantity);
        $this->assertFalse($token->fresh()->used);
    }

    // ── Defaults ───────────────────────────────────────────────────────

    public function test_limits_are_off_by_default_and_large_orders_still_check_out(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller);
        $this->assertFalse($product->purchase_limit_enabled);
        $this->assertFalse($product->purchase_limit_exempt_trusted);
        $this->assertNull($product->purchase_limit_max_quantity);
        $this->assertNull($product->purchase_limit_max_order_minor);
        $this->assertNull($product->purchase_limit_max_active_minor);
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer, $product, 40);

        [$response] = $this->placeWeb($buyer, [$seller], [$line]);

        $response->assertSessionMissing('error');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(10, $product->variants()->sole()->stock_quantity);
    }

    public function test_limits_saved_but_switched_off_are_not_enforced(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => false, 'purchase_limit_max_order_minor' => 5000, 'purchase_limit_max_quantity' => 1]);
        $buyer = Buyer::factory()->create();

        [$response] = $this->placeWeb($buyer, [$seller], [$this->line($buyer, $product, 3)]);

        $response->assertSessionMissing('error');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_limits_apply_only_to_the_product_they_are_set_on(): void
    {
        $seller = $this->shop();
        $limited = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_quantity' => 1]);
        $free = $this->product($seller);
        $buyer = Buyer::factory()->create();
        $limitedLine = $this->line($buyer, $limited, 1);
        $this->line($buyer, $free, 10);

        $this->assertSame([], app(PurchaseLimitService::class)->problems($buyer, $this->summaryFor($buyer)));

        $limitedLine->forceFill(['quantity' => 2])->save();
        $problems = app(PurchaseLimitService::class)->problems($buyer, $this->summaryFor($buyer));
        $this->assertSame([$limited->id], array_keys($problems));
    }

    // ── Units and value per order ──────────────────────────────────────

    public function test_order_over_the_value_limit_is_refused_on_review_and_on_place_without_side_effects(): void
    {
        Notification::fake();
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 15000]);
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer, $product, 2);
        $variant = $line->variant;
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $expected = 'up to ₱150.00 of "'.$product->name.'" per order';

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index', ['items' => [$line->id]]))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($m) => str_contains($m, $expected));

        // A crafted submit that skips the review step is still refused by placeOrder().
        $this->flushSession();
        [$response, $token] = $this->placeWeb($buyer, [$seller], [$line]);

        $response->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, $expected) && ! str_contains(strtolower($m), 'fraud'));
        $this->assertNothingPlaced($token, $variant, 50);
        $this->assertModelExists($line);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('cod_delivery_strikes', 0);
        $this->assertSame('approved', $buyer->fresh()->status);
    }

    public function test_order_exactly_at_the_value_limit_is_allowed(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 20000]);
        $buyer = Buyer::factory()->create();

        [$response] = $this->placeWeb($buyer, [$seller], [$this->line($buyer, $product, 2)]);

        $response->assertSessionMissing('error');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unit_limit_counts_all_variants_of_the_product(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_quantity' => 2]);
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer, $product, 2);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '100.00', 'stock_quantity' => 10, 'is_available' => true]);
        CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $second->id, 'quantity' => 1]);

        $problems = app(PurchaseLimitService::class)->problems($buyer, $this->summaryFor($buyer));

        $this->assertStringContainsString('up to 2 of "'.$product->name.'" per order', $problems[$product->id]);
        $line->forceFill(['quantity' => 1])->save();
        $this->assertSame([], app(PurchaseLimitService::class)->problems($buyer, $this->summaryFor($buyer)));
    }

    public function test_value_uses_the_products_share_of_discounts_and_excludes_shipping(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 15000]);
        $other = $this->product($seller);
        $buyer = Buyer::factory()->create();
        $this->line($buyer, $product, 2); // ₱200 of this product
        $this->line($buyer, $other, 2);   // ₱200 of another product; shop subtotal ₱400
        $service = app(PurchaseLimitService::class);

        $this->assertArrayHasKey($product->id, $service->problems($buyer, $this->summaryFor($buyer)));

        // A ₱120 shop voucher on ₱400 is split by value: this product's share is ₱60, leaving ₱140.
        $voucher = Voucher::factory()->shop($seller)->fixed(12000)->create();
        $summary = $this->summaryFor($buyer, $voucher);
        $this->assertSame(12000, $summary->voucherApplication->shopDiscountFor($seller->id));
        $this->assertSame([], $service->problems($buyer, $summary));
    }

    public function test_discount_share_calculation(): void
    {
        $this->assertSame(14000, PurchaseLimitService::netOfDiscount(20000, 12000, 40000));
        $this->assertSame(20000, PurchaseLimitService::netOfDiscount(20000, 0, 40000));
        $this->assertSame(0, PurchaseLimitService::netOfDiscount(20000, 90000, 20000), 'Never below zero.');
        $this->assertSame(0, PurchaseLimitService::netOfDiscount(0, 500, 1000));
    }

    public function test_existing_order_value_comes_from_its_checkout_snapshot(): void
    {
        $line = new OrderItem(['quantity' => 2, 'price_minor' => 12500]); // ₱250 of the product in a ₱500 parcel
        $parcel = new SellerOrder;
        $parcel->forceFill(['subtotal_minor' => 50000, 'shipping_fee_minor' => 9999,
            'shop_voucher_discount_minor' => 3000, 'platform_voucher_discount_minor' => 2000, 'voucher_discount_minor' => 5000]);
        $this->assertSame(22500, PurchaseLimitService::snapshotValue($parcel, [$line]), 'Half of the ₱50 discount; shipping excluded.');

        $legacy = new SellerOrder;
        $legacy->forceFill(['subtotal_minor' => 50000, 'voucher_discount_minor' => 7000]);
        $this->assertSame(21500, PurchaseLimitService::snapshotValue($legacy, [$line]), 'Older rows use the combined merchandise discount.');
    }

    // ── Unpaid COD value ───────────────────────────────────────────────

    public function test_active_value_counts_only_undelivered_or_failed_uncollected_cod_orders_of_that_product(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_active_minor' => 100000]);
        $sibling = $this->product($seller);
        $buyer = Buyer::factory()->create();
        $service = app(PurchaseLimitService::class);

        foreach ([OrderStatus::Placed, OrderStatus::Preparing, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed,
            OrderStatus::Delivered /* delivered, collection not yet recorded */] as $status) {
            $this->existingOrder($buyer, $product, $status, PaymentStatus::Pending);
        }
        $notCounted = [
            [OrderStatus::Delivered, PaymentStatus::Paid],      // cash collected by the Rider
            [OrderStatus::Completed, PaymentStatus::Paid],
            [OrderStatus::Cancelled, PaymentStatus::Cancelled],
            [OrderStatus::Returned, PaymentStatus::Pending],
            [OrderStatus::Lost, PaymentStatus::Pending],
        ];
        foreach ($notCounted as [$status, $payment]) {
            $this->existingOrder($buyer, $product, $status, $payment);
        }
        $this->existingOrder($buyer, $sibling, OrderStatus::Placed, PaymentStatus::Pending); // another product, same shop
        $this->existingOrder(Buyer::factory()->create(), $product, OrderStatus::Placed, PaymentStatus::Pending); // another Buyer
        // Other goods in the same parcel do not count toward this product.
        $this->existingOrder($buyer, $product, OrderStatus::Placed, PaymentStatus::Pending, 1, 30000);

        $this->assertSame(6 * 10000, $service->activeExposure($buyer, $product));
    }

    public function test_active_value_limit_blocks_the_next_order_until_capacity_returns(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_active_minor' => 25000]);
        $buyer = Buyer::factory()->create();
        $open = $this->existingOrder($buyer, $product, OrderStatus::Confirmed, PaymentStatus::Pending, 2);
        $line = $this->line($buyer, $product, 1);

        [$response, $token] = $this->placeWeb($buyer, [$seller], [$line]);

        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'to ₱250.00 per customer across orders that are not yet delivered'));
        $this->assertSame(1, Order::query()->count(), 'Only the existing order.');
        $this->assertFalse($token->fresh()->used);
        $this->assertSame(50, $line->variant->fresh()->stock_quantity);

        // Cancelling the open order frees the capacity.
        $open->forceFill(['status' => OrderStatus::Cancelled])->save();
        $open->payment->forceFill(['status' => PaymentStatus::Cancelled])->save();
        [$retry] = $this->placeWeb($buyer, [$seller], [$line]);
        $retry->assertSessionMissing('error');
        $this->assertSame(2, Order::query()->count());
    }

    public function test_split_orders_cannot_get_around_the_active_value_limit(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 10000, 'purchase_limit_max_active_minor' => 20000]);
        $buyer = Buyer::factory()->create();

        foreach ([true, true, false] as $expected) {
            [$response] = $this->placeWeb($buyer, [$seller], [$this->line($buyer, $product, 1)]);
            $expected ? $response->assertSessionMissing('error') : $response->assertSessionHas('error');
        }
        $this->assertSame(2, Order::query()->count());
    }

    // ── Trusted Buyers ─────────────────────────────────────────────────

    public function test_trusted_buyer_exemption_needs_three_completed_paid_orders_and_the_product_opt_in(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 5000, 'purchase_limit_exempt_trusted' => true]);
        $earlier = $this->product($seller); // trust is earned at the shop, on any product
        $buyer = Buyer::factory()->create();
        $this->line($buyer, $product, 1);

        $this->existingOrder($buyer, $earlier, OrderStatus::Completed, PaymentStatus::Paid);
        $this->existingOrder($buyer, $earlier, OrderStatus::Completed, PaymentStatus::Paid);
        // Completed elsewhere, or cancelled here, never counts toward trust.
        $this->existingOrder($buyer, $this->product($this->shop()), OrderStatus::Completed, PaymentStatus::Paid);
        $this->existingOrder($buyer, $earlier, OrderStatus::Cancelled, PaymentStatus::Cancelled);
        $this->assertFalse((new PurchaseLimitService)->isTrusted($buyer, $seller->id));
        $this->assertNotSame([], (new PurchaseLimitService)->problems($buyer, $this->summaryFor($buyer)));

        $this->existingOrder($buyer, $earlier, OrderStatus::Completed, PaymentStatus::Paid);
        $this->assertTrue((new PurchaseLimitService)->isTrusted($buyer, $seller->id));
        $this->assertSame([], (new PurchaseLimitService)->problems($buyer, $this->summaryFor($buyer)));

        $product->forceFill(['purchase_limit_exempt_trusted' => false])->save();
        $this->assertNotSame([], (new PurchaseLimitService)->problems($buyer, $this->summaryFor($buyer)), 'Exemption is off unless the Seller turns it on.');
    }

    // ── Multi-Seller and API ───────────────────────────────────────────

    public function test_multi_seller_checkout_checks_each_product_separately(): void
    {
        $strict = $this->shop();
        $open = $this->shop();
        $limited = $this->product($strict, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 15000]);
        $buyer = Buyer::factory()->create();
        $lineA = $this->line($buyer, $limited, 1);                 // ₱100: within the limit
        $lineB = $this->line($buyer, $this->product($open), 5);    // ₱500 of an unlimited product

        [$response] = $this->placeWeb($buyer, [$strict, $open], [$lineA, $lineB]);
        $response->assertSessionMissing('error');
        $this->assertDatabaseCount('seller_orders', 2);
    }

    public function test_api_quote_returns_conflict_with_the_shop_message(): void
    {
        $seller = $this->shop();
        $product = $this->product($seller, ['purchase_limit_enabled' => true, 'purchase_limit_max_order_minor' => 15000]);
        $buyer = Buyer::factory()->create();
        $line = $this->line($buyer, $product, 2);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        Sanctum::actingAs($buyer, ['buyer']);

        $this->postJson('/api/buyer/checkout/quote', ['item_ids' => [$line->id]])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'up to ₱150.00 of "'.$product->name.'" per order'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(50, $line->variant->fresh()->stock_quantity);
    }
}
