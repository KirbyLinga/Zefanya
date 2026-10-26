<?php

namespace Tests\Feature\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\CartSummary;
use App\Services\CheckoutCarrierService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CheckoutCarrierSelectionTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $sellerA;

    private Seller $sellerB;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create();
        $this->sellerA = Seller::factory()->create(['status' => 'approved', 'business_name' => 'Shop Alpha']);
        $this->sellerB = Seller::factory()->create(['status' => 'approved', 'business_name' => 'Shop Beta']);
        $this->productA = Product::factory()->create([
            'seller_id' => $this->sellerA->id,
            'price' => 80.00,
            'stock_quantity' => 5,
        ]);
        $this->productB = Product::factory()->create([
            'seller_id' => $this->sellerB->id,
            'price' => 40.00,
            'stock_quantity' => 5,
        ]);
    }

    public function test_same_province_checkout_lists_eligible_enabled_carriers_with_fees(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $second = $this->makeOperationalCheckoutCarrier('Second Cargo', '0305401001');
        SellerShippingProvider::query()->create([
            'seller_id' => $this->sellerA->id,
            'logistics_provider_id' => $second->id,
            'is_enabled' => true,
        ]);
        // Enabled but no destination coverage for buyer's barangay.
        $noCoverage = $this->makeOperationalCheckoutCarrier('No Coverage Cargo', '0305401002');
        SellerShippingProvider::query()->create([
            'seller_id' => $this->sellerA->id,
            'logistics_provider_id' => $noCoverage->id,
            'is_enabled' => true,
        ]);

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Checkout Carrier')
            ->assertSee('Second Cargo')
            ->assertDontSee('No Coverage Cargo')
            ->assertSee('₱49.00')
            ->assertSee('name="carriers['.$this->sellerA->id.']"', false);
    }

    public function test_cross_province_route_is_unavailable_without_provider_links(): void
    {
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $this->buyer->forceFill([
            'province_code' => '030140000',
            'province_name' => 'Bulacan',
            'municipality_code' => '030140100',
            'municipality_name' => 'Malolos',
            'barangay_code' => '0301401001',
            'barangay_name' => 'Other',
        ])->save();

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Cross-province delivery is not available yet')
            ->assertSee('Destination-provider links are required');
    }

    public function test_no_carrier_blocks_checkout_with_a_clear_message(): void
    {
        $this->buyer->forceFill([
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
        ])->save();
        $this->sellerA->forceFill([
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
        ])->save();

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('No shipping carrier serves this route');

        session([CheckoutController::SESSION_KEY => [$this->buyer->cartItems()->sole()->id]]);

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
        ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHasErrors();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_multi_seller_cart_requires_one_carrier_per_seller(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA, $this->sellerB]);

        $cartA = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        $cartB = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Shop Alpha')
            ->assertSee('Shop Beta')
            ->assertSee('name="carriers['.$this->sellerA->id.']"', false)
            ->assertSee('name="carriers['.$this->sellerB->id.']"', false);

        session([CheckoutController::SESSION_KEY => [$cartA->id, $cartB->id]]);

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            'carriers' => [$this->sellerA->id => $carrier->id],
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('carriers.'.$this->sellerB->id);

        $this->assertSame(0, Order::query()->count());

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            'carriers' => [
                $this->sellerA->id => $carrier->id,
                $this->sellerB->id => $carrier->id,
            ],
        ])->assertRedirect(route('buyer.checkout.success', Order::query()->sole()));
    }

    public function test_fee_is_free_when_group_subtotal_meets_threshold(): void
    {
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $this->productA->variants()->sole()->update(['price' => 1500.00]);
        $this->productA->syncVariantRollups();

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertSee('Free');
    }

    public function test_forged_shipping_price_fields_are_rejected(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->sellerA->id => $carrier->id],
                'shipping_fee' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('shipping');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_forged_carrier_id_is_rejected(): void
    {
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $foreign = $this->makeOperationalCheckoutCarrier('Foreign Cargo', '0305401001');
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->sellerA->id => $foreign->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('carriers.'.$this->sellerA->id);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_disabled_carrier_is_not_offered(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        SellerShippingProvider::query()
            ->where('seller_id', $this->sellerA->id)
            ->where('logistics_provider_id', $carrier->id)
            ->update(['is_enabled' => false]);

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertDontSee($carrier->business_name)
            ->assertSee('No shipping carrier serves this route');
    }

    public function test_carrier_without_seller_origin_coverage_is_not_offered(): void
    {
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $this->buyer->forceFill([
            'barangay_code' => '0305401002',
            'barangay_name' => 'Second',
        ])->save();

        // Operates at the buyer's destination barangay but not the seller's
        // pickup barangay, so it cannot collect the parcel.
        $destinationOnly = $this->makeOperationalCheckoutCarrier('Destination Only Cargo', '0305401002');
        SellerShippingProvider::query()->create([
            'seller_id' => $this->sellerA->id,
            'logistics_provider_id' => $destinationOnly->id,
            'is_enabled' => true,
        ]);

        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk()
            ->assertDontSee('Destination Only Cargo')
            ->assertSee('No shipping carrier serves this route');
    }

    public function test_cross_province_submission_is_blocked_even_with_an_enabled_carrier(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $this->buyer->forceFill([
            'province_code' => '030140000',
            'province_name' => 'Bulacan',
            'municipality_code' => '030140100',
            'municipality_name' => 'Malolos',
            'barangay_code' => '0301401001',
            'barangay_name' => 'Other',
        ])->save();
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->sellerA->id => $carrier->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('carriers.'.$this->sellerA->id);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_multi_seller_fees_are_calculated_per_group(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA, $this->sellerB]);
        $this->productB->variants()->sole()->update(['price' => 1500.00]);
        $this->productB->syncVariantRollups();
        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
        ]);

        $summary = new CartSummary(
            $this->buyer->cartItems()->with(['product.seller', 'variant'])->get(),
        );
        $quote = app(CheckoutCarrierService::class)->validateSelection(
            $this->buyer->fresh(),
            $summary->sellerGroups,
            [
                $this->sellerA->id => $carrier->id,
                $this->sellerB->id => $carrier->id,
            ],
        );

        $this->assertSame(4900, $quote['fees'][$this->sellerA->id]);
        $this->assertSame(0, $quote['fees'][$this->sellerB->id]);
        $this->assertSame(4900, $quote['shipping_total_minor']);
    }

    public function test_array_carrier_payload_is_rejected(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
                'carriers' => [$this->sellerA->id => ['id' => $carrier->id]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('carriers.'.$this->sellerA->id);

        // The invalid array is flashed back; the checkout page must still render.
        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.checkout.index'))
            ->assertOk();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_validate_selection_never_trusts_submitted_fees(): void
    {
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->sellerA]);
        $cart = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
        $summary = new CartSummary(
            $this->buyer->cartItems()->with(['product.seller', 'variant'])->get(),
        );

        $quote = app(CheckoutCarrierService::class)->validateSelection(
            $this->buyer->fresh(),
            $summary->sellerGroups,
            [$this->sellerA->id => $carrier->id],
        );

        $this->assertSame(4900, $quote['fees'][$this->sellerA->id]);
        $this->assertSame(4900, $quote['shipping_total_minor']);
        $this->assertSame($carrier->id, $quote['selections'][$this->sellerA->id]);
        $this->assertSame(0, Order::query()->count());
        $this->assertTrue($cart->fresh()->exists);
    }
}
