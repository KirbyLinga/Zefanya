<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Models\SellerOrder;
use App\Services\CartSummary;
use App\Services\CheckoutCarrierService;
use App\Services\ProviderHandoffSchemaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class CrossProvinceCheckoutTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private Seller $seller;

    private Buyer $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buyer.cross_province.fee_minor' => 19900, 'buyer.cross_province.free_shipping_threshold_minor' => null]);

        $this->admin = Admin::factory()->create();
        $this->origin = $this->operationalHandoffProvider(
            'Origin Logistics',
            '030540000',
            'Pampanga',
            '030540100',
            'San Fernando',
            '0305401001',
            'Santo Rosario',
        )['provider'];
        $this->destination = $this->operationalHandoffProvider(
            'Metro Destination Logistics',
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
            '130000000',
            'National Capital Region',
        )['provider'];

        $this->seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $this->buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');

        SellerShippingProvider::query()->create([
            'seller_id' => $this->seller->id,
            'logistics_provider_id' => $this->origin->id,
            'is_enabled' => true,
        ]);

        $this->product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'price' => 250.00,
            'stock_quantity' => 5,
        ]);
    }

    public function test_cross_province_checkout_displays_the_enabled_origin_and_its_approved_destination(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $this->cart();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Cross-province delivery')
            ->assertSee('Origin Logistics')
            ->assertSee('Metro Destination Logistics')
            ->assertSee('₱199.00')
            ->assertSee('name="carriers['.$this->seller->id.']"', false);
    }

    public function test_cross_province_route_is_unavailable_without_an_approved_destination_partner(): void
    {
        $this->cart();

        $service = app(CheckoutCarrierService::class);
        $summary = new CartSummary($this->buyer->cartItems()->with(['product.seller', 'variant'])->get());
        $options = $service->optionsForCheckout($this->buyer, $summary->sellerGroups);

        $this->assertFalse($options[0]['available']);
        $this->assertSame('cross_province', $options[0]['route']);
        $this->assertStringContainsString('Destination-provider links are required', (string) $options[0]['message']);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Cross-province delivery is not available yet');
    }

    public function test_cross_province_route_is_blocked_when_no_rate_is_configured(): void
    {
        config(['buyer.cross_province.fee_minor' => null]);
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $this->cart();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Cross-province shipping is not configured by the marketplace yet');
    }

    public function test_cross_province_order_saves_origin_carrier_and_destination_without_transferring_custody(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $cart = $this->cart();
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $response = $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->seller->id => $this->origin->id],
            ]);

        $response->assertRedirect();
        $order = Order::query()->sole();
        $sellerOrder = SellerOrder::query()->sole();

        $this->assertSame($this->origin->id, (int) $sellerOrder->carrier_provider_id);
        $this->assertSame($this->destination->id, (int) $sellerOrder->handoff_destination_provider_id);
        $this->assertSame(19900, (int) $sellerOrder->shipping_fee_minor);
        $this->assertNull($sellerOrder->custody_provider_id);
        $this->assertNull($sellerOrder->custody_hub_id);
        $this->assertSame(OrderStatus::Placed, $sellerOrder->status);
        $this->assertSame($this->origin->id, (int) $order->sellerOrders()->sole()->carrier_provider_id);
        $this->assertDatabaseCount('parcel_handoffs', 0);
        $this->assertDatabaseCount('parcel_handoff_exceptions', 0);
    }

    public function test_cross_province_route_is_revalidated_against_locked_links_before_order_creation(): void
    {
        $link = $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $this->cart();

        $service = app(CheckoutCarrierService::class);
        $summary = new CartSummary($this->buyer->cartItems()->with(['product.seller', 'variant'])->get());
        $quote = $service->validateSelection($this->buyer, $summary->sellerGroups, [
            $this->seller->id => $this->origin->id,
        ]);
        $this->assertTrue($quote['options'][0]['available']);
        $this->assertSame(19900, $quote['fees'][$this->seller->id]);

        app(ProviderHandoffSchemaService::class)->revoke($link, $this->admin, 'Route withdrawn between quote and payment.');

        $this->expectException(ValidationException::class);
        $service->validateSelectionForOrder($this->buyer, $summary->sellerGroups, [
            $this->seller->id => $this->origin->id,
        ]);
    }

    public function test_priority_orders_the_destination_partner(): void
    {
        $preferred = $this->operationalHandoffProvider(
            'Preferred Metro Logistics',
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
            '130000000',
            'National Capital Region',
        )['provider'];

        $this->activeHandoffLink($this->origin, $this->destination, $this->admin, 50);
        $this->activeHandoffLink($this->origin, $preferred, $this->admin, 1);
        $this->cart();

        $service = app(CheckoutCarrierService::class);
        $summary = new CartSummary($this->buyer->cartItems()->with(['product.seller', 'variant'])->get());
        $quote = $service->validateSelection($this->buyer, $summary->sellerGroups, [
            $this->seller->id => $this->origin->id,
        ]);

        $this->assertSame($preferred->id, (int) $quote['options'][0]['carriers'][0]['destination_id']);
        $this->assertSame('Preferred Metro Logistics', $quote['options'][0]['carriers'][0]['destination_name']);
    }

    public function test_cross_province_submission_rejects_client_supplied_fees(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $cart = $this->cart();
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->seller->id => $this->origin->id],
                'shipping_fee' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('shipping');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_multi_seller_cross_province_checkout_keeps_per_seller_ownership_and_fees(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);

        $secondSeller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        SellerShippingProvider::query()->create([
            'seller_id' => $secondSeller->id,
            'logistics_provider_id' => $this->origin->id,
            'is_enabled' => true,
        ]);
        $secondProduct = Product::factory()->create([
            'seller_id' => $secondSeller->id,
            'price' => 150.00,
            'stock_quantity' => 5,
        ]);

        $firstCart = $this->cart();
        $secondCart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $secondProduct->id,
            'quantity' => 1,
        ]);
        session([CheckoutController::SESSION_KEY => [$firstCart->id, $secondCart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [
                    $this->seller->id => $this->origin->id,
                    $secondSeller->id => $this->origin->id,
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(2, Order::query()->sole()->sellerOrders()->count());

        foreach (SellerOrder::query()->get() as $sellerOrder) {
            $this->assertSame($this->origin->id, (int) $sellerOrder->carrier_provider_id);
            $this->assertSame($this->destination->id, (int) $sellerOrder->handoff_destination_provider_id);
            $this->assertSame(19900, (int) $sellerOrder->shipping_fee_minor);
            $this->assertNull($sellerOrder->custody_provider_id);
            $this->assertSame(OrderStatus::Placed, $sellerOrder->status);
        }

        $this->assertSame(40000, (int) Order::query()->sole()->subtotal_minor);
        $this->assertSame(79800, (int) Order::query()->sole()->total_minor);
        $this->assertSame([34900, 44900], Payment::query()->orderBy('amount_minor')->pluck('amount_minor')->all());
        $this->assertSame(2, Payment::query()->where('method', 'cod')->where('status', 'pending')->count());

        $this->assertDatabaseCount('parcel_handoffs', 0);
    }

    public function test_invalid_cross_province_fees_never_become_a_zero_or_truncated_rate(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        foreach ([-0.5, 19900.5, '19900.5', 'not-configured', '99999999999999999999999999999'] as $fee) {
            config(['buyer.cross_province.fee_minor' => $fee]);
            $options = app(CheckoutCarrierService::class)->optionsForSellerGroup($this->buyer, $this->seller, 25000);
            $this->assertFalse($options['available']);
            $this->assertSame([], $options['carriers']);
            $this->assertStringContainsString('not configured', $options['message']);
        }
    }

    private function cart(): CartItem
    {
        return CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);
    }
}
