<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * The web checkout page, the app's checkout quote and the placed order must
 * price the same cart identically, down to the centavo, and the order's money
 * columns must add up to what the buyer pays on delivery.
 */
class PriceCalculationParityTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    /** @var list<Seller> */
    private array $sellers;

    /** @var list<int> */
    private array $lineIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'street' => 'Home Street']);
        $this->sellers = [Seller::factory()->create(['status' => 'approved']), Seller::factory()->create(['status' => 'approved'])];
        $this->bootstrapCheckoutCarriers($this->buyer, $this->sellers);

        BuyerAddress::query()->create([
            'buyer_id' => $this->buyer->id, 'address_mode' => 'api',
            'province_code' => '030540000', 'province_name' => 'Pampanga',
            'municipality_code' => '030540100', 'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001', 'barangay_name' => 'First',
            'street' => 'Home Street', 'is_default' => true, 'default_marker' => 1,
        ]);

        // Odd centavo prices and quantities catch rounding drift between paths.
        foreach ([[$this->sellers[0], 199.99, 3], [$this->sellers[0], 45.50, 1], [$this->sellers[1], 1234.57, 2]] as [$seller, $price, $qty]) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => $price, 'stock_quantity' => 10, 'status' => 'active', 'weight_grams' => 700]);
            $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
            $variant->forceFill(['price' => $price, 'stock_quantity' => 10, 'is_available' => true, 'weight_grams' => null])->save();
            $this->lineIds[] = CartItem::factory()->create([
                'buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $qty,
            ])->id;
        }
    }

    private function api(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    /** @return array<string, int> */
    private function webTotals(?string $voucher = null): array
    {
        $this->actingAs($this->buyer, 'buyer');
        if ($voucher !== null) {
            $this->post(route('buyer.cart.voucher.apply'), ['code' => $voucher])->assertSessionHasNoErrors();
        }
        $page = $this->get(route('buyer.checkout.index', ['items' => $this->lineIds]))->assertOk();
        $summary = $page->viewData('summary');

        return [
            'merchandise_subtotal_minor' => $summary->subtotalCentavos,
            'merchandise_discount_minor' => $summary->discountCentavos,
            'merchandise_total_minor' => $page->viewData('merchandiseTotalMinor'),
            'shipping_total_minor' => $page->viewData('shippingTotalMinor'),
            'shipping_discount_minor' => $page->viewData('shippingDiscountMinor'),
            'payable_total_minor' => $page->viewData('payableTotalMinor'),
        ];
    }

    /** @param array<string, mixed> $extra */
    private function apiQuote(array $extra = []): array
    {
        return $this->api()->postJson('/api/buyer/checkout/quote', ['item_ids' => $this->lineIds]
            + $this->checkoutCarrierPayload($this->sellers) + $extra)->assertOk()->json('data');
    }

    private function placeViaApi(array $quote, array $extra = []): Order
    {
        $id = $this->api()->postJson('/api/buyer/checkout', [
            'item_ids' => $this->lineIds,
            'checkout_token' => $quote['checkout_token'],
            'price_fingerprint' => $quote['fingerprints']['price'],
            'address_fingerprint' => $quote['fingerprints']['address'],
            'shipping_fingerprint' => $quote['fingerprints']['shipping'],
            'voucher_fingerprint' => $quote['fingerprints']['voucher'],
            // A tampered client total is ignored; the server prices the order.
            'total_minor' => 1,
        ] + $this->checkoutCarrierPayload($this->sellers) + $extra)->assertCreated()->json('data.id');

        return Order::query()->findOrFail($id);
    }

    private function assertOrderAddsUp(Order $order, array $expected): void
    {
        $this->assertSame($expected['payable_total_minor'], (int) $order->total_minor);
        $this->assertSame($expected['merchandise_subtotal_minor'], (int) $order->subtotal_minor);
        $this->assertSame(
            (int) $order->total_minor,
            (int) $order->subtotal_minor - (int) $order->discount_minor + $expected['shipping_total_minor'] - (int) $order->shipping_discount_minor,
        );

        $sellerOrders = SellerOrder::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $sellerOrders);
        $this->assertSame($expected['merchandise_subtotal_minor'], (int) $sellerOrders->sum('subtotal_minor'));
        $this->assertSame($expected['shipping_total_minor'], (int) $sellerOrders->sum('shipping_fee_minor'));

        // One COD payment per parcel; together they are exactly what the buyer pays.
        $payments = Payment::query()->whereIn('seller_order_id', $sellerOrders->modelKeys())->get();
        $this->assertCount(2, $payments);
        $this->assertSame((int) $order->total_minor, (int) $payments->sum('amount_minor'));

        foreach ($sellerOrders as $sellerOrder) {
            $lines = (int) OrderItem::query()->where('seller_order_id', $sellerOrder->id)->get()->sum(fn ($item): int => (int) $item->price_minor * (int) $item->quantity);
            $this->assertSame((int) $sellerOrder->subtotal_minor, $lines, 'Order lines must add up to the parcel subtotal.');
        }
    }

    public function test_web_page_app_quote_and_placed_order_price_the_cart_identically(): void
    {
        // 3 x 199.99 + 45.50 + 2 x 1234.57
        $expectedSubtotal = 59997 + 4550 + 246914;

        $web = $this->webTotals();
        $quote = $this->apiQuote();

        $this->assertSame($expectedSubtotal, $web['merchandise_subtotal_minor']);
        $this->assertSame($web, $quote['totals']);

        $this->assertOrderAddsUp($this->placeViaApi($quote), $web);
    }

    public function test_a_platform_voucher_discounts_the_same_on_web_and_app(): void
    {
        Voucher::factory()->create(['code' => 'PARITY75', 'value' => 7500]);

        $web = $this->webTotals('PARITY75');
        $state = $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => 'parity75'])->assertOk()->json('data.vouchers_state');
        $quote = $this->apiQuote(['vouchers' => $state]);

        $this->assertSame(7500, $web['merchandise_discount_minor']);
        $this->assertSame($web, $quote['totals']);

        $order = $this->placeViaApi($quote, ['vouchers' => $state]);
        $this->assertSame(7500, (int) $order->discount_minor);
        $this->assertOrderAddsUp($order, $web);
    }

    public function test_a_percentage_voucher_rounds_the_same_on_web_and_app(): void
    {
        Voucher::factory()->create(['code' => 'ODD7', 'type' => 'percent', 'value' => 7, 'max_discount' => null]);

        $web = $this->webTotals('ODD7');
        $state = $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => 'ODD7'])->assertOk()->json('data.vouchers_state');
        $quote = $this->apiQuote(['vouchers' => $state]);

        $this->assertGreaterThan(0, $web['merchandise_discount_minor']);
        $this->assertSame($web, $quote['totals']);
        $this->assertOrderAddsUp($this->placeViaApi($quote, ['vouchers' => $state]), $web);
    }
}
