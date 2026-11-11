<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\VoucherScope;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\VoucherSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class P1ShoppingPresentationTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    public function test_price_filter_preserves_sale_filter_and_empty_recovery_preserves_search(): void
    {
        $response = $this->get(route('buyer.products.index', ['q' => 'Unfindablezzzz', 'on_sale' => 1, 'min_price' => 10, 'sort' => 'price_asc']))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('1', $xpath->query('//form[@data-price-filter-form]//input[@name="on_sale"]')->item(0)->getAttribute('value'));
        $link = $xpath->query('//a[contains(normalize-space(.), "Clear filters and keep search")]')->item(0);
        $this->assertNotNull($link);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame('Unfindablezzzz', $query['q']);
        $this->assertArrayNotHasKey('min_price', $query);
        $this->assertArrayNotHasKey('on_sale', $query);
    }

    public function test_product_explains_stock_and_existing_return_window(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'stock_quantity' => 17]);
        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('17 unit(s) available')
            ->assertSee('Delivery and returns')
            ->assertSee('within 7 days of verified delivery, before confirming receipt')
            ->assertSee('1 to 5 clear JPG or PNG photos')
            ->assertSee('Eligible return reasons')
            ->assertSee('Adding an item to your cart does not reserve it.');
    }

    public function test_seller_guidance_matches_fulfillment_status_and_stays_scoped(): void
    {
        $seller = Seller::factory()->create();
        $own = SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Preparing]);
        $other = SellerOrder::factory()->create(['status' => OrderStatus::Placed]);
        $this->actingAs($seller, 'seller')->get(route('seller.orders.index'))->assertOk()
            ->assertSee('Finish packing, then mark the parcel ready for pickup.')
            ->assertDontSee('Accept the order to begin fulfillment, or review it before declining.');
        $this->get(route('seller.orders.show', $own))->assertOk()->assertSee('data-fulfillment-guidance', false);
        $this->get(route('seller.orders.show', $other))->assertForbidden();
    }

    public function test_server_shipping_breakdown_shows_gross_fee_and_shipping_savings_separately(): void
    {
        $buyer = Buyer::factory()->create(['street' => '1 Test Street']);
        $seller = Seller::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        config(['buyer.shipping_fee.base_minor' => 5000]);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active', 'price' => 100, 'weight_grams' => 500]);
        $variant = $product->variants()->firstOrFail();
        $variant->forceFill(['price' => 100, 'weight_grams' => null])->save();
        $cart = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        Voucher::factory()->fixed(1000)->create(['code' => 'SHIPTEN', 'scope' => VoucherScope::Shipping]);
        $response = $this->actingAs($buyer, 'buyer')->withSession([VoucherSessionService::SESSION_KEY => ['shipping' => 'SHIPTEN']])
            ->get(route('buyer.checkout.index', ['items' => [$cart->id]]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame("\u{20b1}50.00", trim($xpath->query('//*[@data-checkout-shipping-total]')->item(0)->textContent));
        $this->assertSame("-\u{20b1}10.00", trim($xpath->query('//*[@data-checkout-shipping-discount]')->item(0)->textContent));
        $this->assertSame("\u{20b1}10.00", trim($xpath->query('//*[@data-checkout-savings]')->item(0)->textContent));
        $this->assertSame("\u{20b1}140.00", trim($xpath->query('//*[@data-checkout-payable-total]')->item(0)->textContent));
    }
}
