<?php

namespace Tests\Feature\Services;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\OrderPlacer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The placing rules, on their own: no controller, no session, no view.
 *
 * These rules used to live inside the web's `CheckoutController`, where the only
 * test that reached them went through a POST and then a *rendered* confirmation
 * page — which this repo cannot render, because the frontend is not built here, so
 * the test died on the Vite manifest before asserting anything about the rows it
 * had just written. The rules were effectively untested, and the app's door could
 * not reach them at all.
 *
 * So this is the service's own test: lines in, rows out.
 */
class OrderPlacerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seller(string $name): Seller
    {
        return Seller::factory()->create([
            'business_name' => $name,
            'status' => 'approved',
        ]);
    }

    private function product(
        Seller $seller,
        float $price = 1250.75,
        int $stock = 10,
        string $name = 'Handwoven Rattan Tote',
    ): Product {
        return Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
        ]);
    }

    public function test_it_writes_the_order_its_parcel_its_line_its_payment_and_the_ledger(): void
    {
        $seller = $this->seller('Loom & Larder');
        $product = $this->product($seller, price: 1250.75, stock: 10);
        $buyer = Buyer::factory()->create();

        $order = app(OrderPlacer::class)->place($buyer, [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);

        // Centavos, from the server's own price: 1250.75 × 2.
        $this->assertSame(250150, $order->subtotal_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(250150, $order->total_minor);
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertStringStartsWith('ORD-', $order->reference);

        $parcel = SellerOrder::query()->sole();
        $this->assertSame($order->id, $parcel->order_id);
        $this->assertSame($seller->id, $parcel->seller_id);
        $this->assertSame(250150, $parcel->subtotal_minor);

        $line = OrderItem::query()->sole();
        $this->assertSame($parcel->id, $line->seller_order_id);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('Handwoven Rattan Tote', $line->product_name);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(125075, $line->price_minor);

        // COD and pending, for the parcel's full subtotal when no voucher was given.
        $payment = Payment::query()->sole();
        $this->assertSame($parcel->id, $payment->seller_order_id);
        $this->assertSame(250150, $payment->amount_minor);

        // Stock moved, and the append-only ledger says so in the same transaction.
        $this->assertSame(8, $product->fresh()->stock_quantity);

        $ledger = InventoryTransaction::query()->sole();
        $this->assertSame($product->id, $ledger->product_id);
        $this->assertSame($parcel->id, $ledger->seller_order_id);
        $this->assertSame(10, $ledger->quantity_before);
        $this->assertSame(8, $ledger->quantity_after);
        $this->assertSame(-2, $ledger->delta);
        $this->assertSame('checkout', $ledger->reason);
    }

    public function test_a_two_shop_checkout_splits_the_discount_so_the_payments_sum_to_the_total(): void
    {
        $loom = $this->seller('Loom & Larder');
        $tahanan = $this->seller('Tahanan Ceramics');

        $tote = $this->product($loom, price: 1000.00);
        $bowls = $this->product($tahanan, price: 500.00, name: 'Stoneware Rice Bowl Set');

        // ₱200 off a ₱1,500 basket: not a clean division, so the largest-remainder
        // rule is doing the work rather than a lucky ratio.
        $voucher = Voucher::factory()->fixed(20000)->create(['code' => 'ZEFANYANEW']);

        $order = app(OrderPlacer::class)->place(
            Buyer::factory()->create(),
            [
                ['product_id' => $tote->id, 'quantity' => 1],
                ['product_id' => $bowls->id, 'quantity' => 1],
            ],
            $voucher,
        );

        $this->assertSame(150000, $order->subtotal_minor);
        $this->assertSame(20000, $order->discount_minor);
        $this->assertSame(130000, $order->total_minor);

        // One parcel per shop, each with its own subtotal rather than the order's.
        $this->assertSame(
            [100000, 50000],
            SellerOrder::query()->orderBy('id')->pluck('subtotal_minor')->all(),
        );

        // And the two COD payments sum *exactly* to the discounted total — the
        // reason the allocation is proportional in the first place.
        $payments = Payment::query()->orderBy('id')->pluck('amount_minor')->all();

        $this->assertCount(2, $payments);
        $this->assertSame(130000, array_sum($payments));
    }

    public function test_a_line_with_no_stock_rolls_the_whole_order_back(): void
    {
        $product = $this->product($this->seller('Loom & Larder'), stock: 1);

        try {
            app(OrderPlacer::class)->place(Buyer::factory()->create(), [
                ['product_id' => $product->id, 'quantity' => 2],
            ]);

            $this->fail('A line with no stock must not be placeable.');
        } catch (CheckoutException) {
            // Expected: the authoritative gate runs under the lock.
        }

        // Nothing partial — no order, no parcel, no payment, no ledger row, and the
        // stock that was there is still there.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_a_product_that_is_no_longer_on_sale_is_refused_under_the_lock(): void
    {
        $draft = $this->product($this->seller('Loom & Larder'), name: 'Prototype Weave Sample');
        $draft->forceFill(['status' => 'inactive'])->save();

        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), [
            ['product_id' => $draft->id, 'quantity' => 1],
        ]);
    }

    public function test_a_product_that_does_not_exist_is_refused_too(): void
    {
        // No error from a missing row: a line naming a product nobody has is
        // "not buyable", which is the same answer the cart page gives.
        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), [
            ['product_id' => 999999, 'quantity' => 1],
        ]);
    }

    public function test_the_shipping_address_is_a_snapshot_of_the_buyer_at_order_time(): void
    {
        $product = $this->product($this->seller('Loom & Larder'));

        $buyer = Buyer::factory()->create([
            'first_name' => 'Marites',
            'last_name' => 'Santos',
            'contact_no' => '09171234567',
            'house_number' => '42',
            'street' => 'Maginhawa Street',
            'barangay_name' => 'Pinyahan',
            'municipality_name' => 'Quezon City',
            'province_name' => 'Metro Manila',
        ]);

        $order = app(OrderPlacer::class)->place($buyer, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);

        $address = $order->shipping_address;

        $this->assertSame('Marites Santos', $address['name']);
        $this->assertSame('09171234567', $address['phone']);
        $this->assertSame(
            '42, Maginhawa Street, Pinyahan, Quezon City, Metro Manila',
            $address['full'],
        );

        // A snapshot, not a link: editing the profile afterwards must not rewrite
        // what was shipped.
        $buyer->forceFill(['street' => 'Somewhere Else'])->save();

        $this->assertStringContainsString('Maginhawa', $order->fresh()->shipping_address['full']);
    }

    public function test_an_empty_line_list_is_an_error_rather_than_an_empty_order(): void
    {
        // A zero-line order would be a row nobody can fulfil — and an order number
        // for nothing.
        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), []);
    }
}
