<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $sellerA;

    private Seller $sellerB;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create([
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Diliman',
            'street' => 'Maple Street',
            'house_number' => '123',
            'address_detail' => 'Near the corner',
        ]);
        $this->sellerA = Seller::factory()->create();
        $this->sellerB = Seller::factory()->create();

        $this->productA = Product::factory()->create([
            'seller_id' => $this->sellerA->id,
            'name' => 'Walnut Desk',
            'price' => 100.00,
            'stock_quantity' => 5,
        ]);

        $this->productB = Product::factory()->create([
            'seller_id' => $this->sellerB->id,
            'name' => 'Brass Lamp',
            'price' => 25.50,
            'stock_quantity' => 3,
        ]);
    }

    /**
     * Park the checkout selection (same session keys CheckoutController@index uses).
     *
     * @param  array<int, int>  $ids
     */
    private function parkSelection(array $ids, ?string $voucherCode = null): void
    {
        session([
            CheckoutController::SESSION_KEY => $ids,
            'cart_voucher' => $voucherCode,
        ]);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_a_buyer_places_an_order_across_two_sellers(): void
    {
        $cartA = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 2,
        ]);
        $cartB = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
        ]);

        // An unparked line must SURVIVE checkout — only the selection is purchased.
        $unselected = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => Product::factory()->create(['seller_id' => $this->sellerA->id, 'price' => 10.00]),
            'quantity' => 1,
        ]);

        Voucher::factory()->fixed(5000)->create(['code' => 'ZEFANYANEW']);
        $this->parkSelection([$cartA->id, $cartB->id], 'ZEFANYANEW');

        $response = $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'));

        // subtotal = 2×10000 + 1×2550 = 22550; discount 5000; total 17550.
        $order = Order::query()->sole();
        $this->assertSame(22550, $order->subtotal_minor);
        $this->assertSame(5000, $order->discount_minor);
        $this->assertSame(17550, $order->total_minor);
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame($this->buyer->id, $order->buyer_id);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z0-9]{6}$/', $order->reference);

        // Address snapshot from the buyer's profile.
        $this->assertSame($this->buyer->fullName(), $order->shipping_address['name']);
        $this->assertSame('123, Maple Street, Diliman, Quezon City, Metro Manila, Near the corner', $order->shipping_address['full']);
        $this->assertSame($this->buyer->contact_no, $order->shipping_address['phone']);

        // One seller_order per shop, one per (order, seller) pair.
        $this->assertSame(2, $order->sellerOrders()->count());
        $subtotals = $order->sellerOrders->pluck('subtotal_minor', 'seller_id');
        $this->assertSame(20000, (int) $subtotals[$this->sellerA->id]);
        $this->assertSame(2550, (int) $subtotals[$this->sellerB->id]);

        // Order items belong to seller_orders and snapshot name + price.
        $this->assertSame(2, OrderItem::query()->count());
        $itemA = OrderItem::query()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($itemA);
        $this->assertSame(
            $itemA->seller_order_id,
            (int) $order->sellerOrders->firstWhere('seller_id', $this->sellerA->id)->id,
        );
        $this->assertSame('Walnut Desk', $itemA->product_name);
        $this->assertSame(2, $itemA->quantity);
        $this->assertSame(10000, $itemA->price_minor);
        $this->assertSame(2550, (int) OrderItem::query()->where('product_id', $this->productB->id)->value('price_minor'));

        // One pending COD payment per seller_order; they sum to the order total.
        $payments = Payment::query()->get();
        $this->assertSame(2, $payments->count());
        $this->assertTrue($payments->every(fn (Payment $p): bool => $p->status === PaymentStatus::Pending));
        $this->assertTrue($payments->every(fn (Payment $p): bool => $p->method === PaymentMethod::Cod));
        $this->assertNull($payments->first()->paid_at);
        $this->assertSame(17550, (int) $payments->sum('amount_minor'));
        foreach ($payments as $payment) {
            $this->assertLessThanOrEqual($payment->sellerOrder->subtotal_minor, $payment->amount_minor);
            $this->assertGreaterThan(0, $payment->amount_minor);
        }

        // Stock decremented inside the transaction, with a ledger row each.
        $this->assertSame(3, $this->productA->fresh()->stock_quantity);
        $this->assertSame(2, $this->productB->fresh()->stock_quantity);
        $this->assertSame(2, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $ledgerA = InventoryTransaction::query()->where('product_id', $this->productA->id)->first();
        $this->assertSame(5, $ledgerA->quantity_before);
        $this->assertSame(3, $ledgerA->quantity_after);
        $this->assertSame(-2, $ledgerA->delta);

        // Purchased lines removed; the unparked line survives; session cleared.
        $this->assertSame(1, CartItem::where('buyer_id', $this->buyer->id)->count());
        $this->assertTrue($unselected->fresh()->exists);
        $response->assertSessionMissing(CheckoutController::SESSION_KEY);
        $response->assertSessionMissing('cart_voucher');

        // Redirects to the confirmation page showing the reference.
        $response->assertRedirect(route('buyer.checkout.success', $order));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee($order->reference);
    }

    public function test_a_single_seller_order_carries_the_full_discount(): void
    {
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        Voucher::factory()->fixed(5000)->create(['code' => 'ZEFANYANEW']);
        $this->parkSelection([$cart->id], 'ZEFANYANEW');

        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'));

        $order = Order::query()->sole();
        $this->assertSame(10000, $order->subtotal_minor);
        $this->assertSame(5000, $order->discount_minor);
        $this->assertSame(5000, $order->total_minor);

        // One shop: the whole discount lands on its payment.
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(5000, (int) Payment::query()->value('amount_minor'));
    }

    // ── Rejections (no partial writes) ───────────────────────────────────────

    public function test_insufficient_stock_rejects_the_order_without_partial_writes(): void
    {
        // Parked at qty 2, then another buyer drains the stock to 1.
        $cartA = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 2,
        ]);
        $cartB = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
        ]);
        $this->parkSelection([$cartA->id, $cartB->id]);

        $this->productA->update(['stock_quantity' => 1]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        // Nothing was written: no order tree, no stock change, no ledger row.
        // (Stock stays at the DRAINED value — the failed checkout touched nothing.)
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1, $this->productA->fresh()->stock_quantity);
        $this->assertSame(3, $this->productB->fresh()->stock_quantity);
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(2, CartItem::where('buyer_id', $this->buyer->id)->count());
    }

    public function test_an_item_removed_from_the_cart_after_parking_rejects_the_order(): void
    {
        $cartA = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
        ]);
        $this->parkSelection([$cartA->id]);

        $cartA->delete();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_item_that_became_unavailable_after_parking_rejects_the_order(): void
    {
        $cartA = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
        ]);
        $this->parkSelection([$cartA->id]);

        $this->productA->delete(); // soft delete → unavailable

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_tampered_selection_cannot_check_out_another_buyers_line(): void
    {
        $otherBuyer = Buyer::factory()->create();
        $foreignItem = CartItem::factory()->create([
            'buyer_id' => $otherBuyer->id,
            'product_id' => $this->productA->id,
        ]);
        $this->parkSelection([$foreignItem->id]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $this->productA->fresh()->stock_quantity);
    }

    public function test_an_empty_selection_redirects_back_to_the_cart(): void
    {
        $this->parkSelection([]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.cart.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_guests_cannot_place_orders(): void
    {
        $this->parkSelection([999]);

        $this->post(route('buyer.checkout.store'))
            ->assertRedirect(route('buyer.login'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_invalid_parked_voucher_is_dropped_and_the_order_still_places(): void
    {
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
        ]);

        Voucher::factory()->fixed(5000)->expired()->create(['code' => 'OLDCODE']);
        $this->parkSelection([$cart->id], 'OLDCODE');

        $response = $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'));

        $order = Order::query()->sole();
        $this->assertSame(10000, $order->subtotal_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(10000, $order->total_minor);
        $this->assertSame(10000, (int) Payment::query()->value('amount_minor'));
        $response->assertSessionHas('voucher_notice');
    }

    // ── Confirmation page ownership ──────────────────────────────────────────

    public function test_another_buyer_cannot_open_someone_elses_confirmation(): void
    {
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
        ]);
        $this->parkSelection([$cart->id]);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'));

        $order = Order::query()->sole();

        $this->actingAs(Buyer::factory()->create(), 'buyer')
            ->get(route('buyer.checkout.success', $order))
            ->assertNotFound();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.success', $order))
            ->assertOk();
    }
}
