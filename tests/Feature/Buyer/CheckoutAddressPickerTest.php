<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerAddressBook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/** BUY-ADDR-008 and BUY-CHK-002. */
class CheckoutAddressPickerTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private CartItem $line;

    private BuyerAddress $home;

    private BuyerAddress $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->buyer = Buyer::factory()->create(['street' => 'Home Street', 'house_number' => null, 'address_detail' => null, 'postal_code' => null]);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);

        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => 100.00, 'stock_quantity' => 50, 'status' => 'active', 'weight_grams' => 500]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $variant->forceFill(['price' => 100.00, 'stock_quantity' => 50, 'is_available' => true, 'weight_grams' => null])->save();
        $this->line = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);

        $this->home = $this->saved('Home Street', true);
        // Another municipality in the seller's province: the higher same-province fee.
        $this->office = $this->saved('Office Avenue', false, ['municipality_code' => '030540200', 'municipality_name' => 'Angeles']);

        $this->actingAs($this->buyer, 'buyer');
    }

    /** @param array<string, string|null> $overrides */
    private function saved(string $street, bool $default, array $overrides = []): BuyerAddress
    {
        return BuyerAddress::query()->create($overrides + [
            'buyer_id' => $this->buyer->id,
            'address_mode' => 'api',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
            'street' => $street,
            'is_default' => $default,
            'default_marker' => $default ? 1 : null,
        ]);
    }

    private function checkoutUrl(?BuyerAddress $address = null): string
    {
        return route('buyer.checkout.index', array_filter(['items' => [$this->line->id], 'address' => $address?->id]));
    }

    private function openCheckout(?BuyerAddress $address = null): TestResponse
    {
        return $this->get($this->checkoutUrl($address))->assertOk();
    }

    /** Everything the rendered page would submit, so these tests act like a real form. */
    private function formFields(TestResponse $page): array
    {
        $fields = [];

        foreach (['checkout_token', 'price_fingerprint', 'shipping_fingerprint', 'voucher_fingerprint', 'address_id', 'address_fingerprint'] as $name) {
            $this->assertSame(1, preg_match('/name="'.$name.'" value="([^"]*)"/', $page->getContent(), $match), $name);
            $fields[$name] = $match[1];
        }

        return $fields + $this->checkoutCarrierPayload([$this->seller]);
    }

    private function placeOrderIsDisabled(TestResponse $page): bool
    {
        $this->assertSame(1, preg_match('/<button[^>]*data-checkout-place-order[^>]*>/s', $page->getContent(), $button));

        return preg_match('/\sdisabled(?=[\s>=])/', $button[0]) === 1;
    }

    private function placeOrderTo(?BuyerAddress $address): Order
    {
        $this->post(route('buyer.checkout.store'), $this->formFields($this->openCheckout($address)))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Order::query()->where('buyer_id', $this->buyer->id)->sole();
    }

    public function test_the_picker_lists_every_saved_address_with_the_default_selected(): void
    {
        $page = $this->openCheckout();

        $page->assertSee('Home Street')->assertSee('Office Avenue');
        $this->assertTrue($this->home->is($page->viewData('selectedAddress')));
        $this->assertSame(1, substr_count($page->getContent(), 'aria-current="true"'));
    }

    public function test_a_single_saved_address_needs_no_picker(): void
    {
        $this->office->delete();

        $this->openCheckout()->assertSee('Home Street')->assertDontSee('data-checkout-address-picker', false);
    }

    public function test_choosing_an_address_shows_it_and_quotes_shipping_for_it(): void
    {
        $this->assertSame(4900, $this->openCheckout()->viewData('carrierOptions')[0]['carriers'][0]['fee_minor']);

        $page = $this->openCheckout($this->office);

        $this->assertSame(6900, $page->viewData('carrierOptions')[0]['carriers'][0]['fee_minor']);
        $this->assertSame('Office Avenue', $page->viewData('deliverTo')->street);
        $this->assertSame((string) $this->office->id, $this->formFields($page)['address_id']);
    }

    public function test_a_stale_address_link_shows_the_default_instead_of_someone_elses(): void
    {
        $stranger = Buyer::factory()->create();
        $theirs = BuyerAddress::query()->create(['buyer_id' => $stranger->id, 'address_mode' => 'manual', 'street' => 'Private Road']);

        $page = $this->openCheckout($theirs);

        $page->assertDontSee('Private Road');
        $this->assertTrue($this->home->is($page->viewData('selectedAddress')));
    }

    public function test_the_order_is_frozen_to_the_chosen_address_and_charged_for_it(): void
    {
        $order = $this->placeOrderTo($this->office);

        $this->assertSame('Office Avenue', $order->shipping_address['street']);
        $this->assertSame('Angeles', $order->shipping_address['municipality']);
        $this->assertSame('030540200', $order->shipping_address['municipality_code']);
        $this->assertStringContainsString('Office Avenue', $order->shipping_address['full']);
        $this->assertSame($this->buyer->fullName(), $order->shipping_address['name']);
        $this->assertSame(6900, SellerOrder::query()->where('order_id', $order->id)->sole()->shipping_fee_minor);
    }

    public function test_choosing_an_address_does_not_change_the_profile_or_the_default(): void
    {
        $this->placeOrderTo($this->office);

        $this->assertSame('Home Street', $this->buyer->fresh()->street);
        $this->assertSame('San Fernando', $this->buyer->fresh()->municipality_name);
        $this->assertTrue($this->home->fresh()->is_default);
        $this->assertFalse($this->office->fresh()->is_default);
    }

    public function test_an_order_without_a_choice_goes_to_the_default_address(): void
    {
        $order = $this->placeOrderTo(null);

        $this->assertSame('Home Street', $order->shipping_address['street']);
    }

    public function test_a_buyer_with_no_saved_rows_still_checks_out_with_the_profile_address(): void
    {
        BuyerAddress::query()->where('buyer_id', $this->buyer->id)->delete();

        $order = $this->placeOrderTo(null);

        $this->assertSame('Home Street', $order->shipping_address['street']);
    }

    public function test_addr_008_editing_or_deleting_the_address_later_leaves_the_order_alone(): void
    {
        $order = $this->placeOrderTo($this->office);
        $snapshot = $order->shipping_address;

        $this->patch(route('buyer.account.addresses.update', $this->office), [
            'address_mode' => 'manual',
            'province_name' => 'Batangas',
            'municipality_name' => 'Lipa City',
            'barangay_name' => 'Barangay 1',
            'street' => 'Moved Away Road',
        ])->assertRedirect();
        $this->assertSame($snapshot, $order->fresh()->shipping_address);

        $this->delete(route('buyer.account.addresses.destroy', $this->office))->assertRedirect();
        $this->assertSame($snapshot, $order->fresh()->shipping_address);

        $this->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Office Avenue')
            ->assertDontSee('Moved Away Road');
    }

    public function test_addr_008_a_new_default_or_profile_edit_leaves_the_order_and_waybill_alone(): void
    {
        $order = $this->placeOrderTo($this->home);
        $snapshot = $order->shipping_address;
        $sellerOrder = SellerOrder::query()->where('order_id', $order->id)->sole();

        app(BuyerAddressBook::class)->save($this->buyer, [
            'address_mode' => 'manual',
            'province_name' => 'Batangas',
            'municipality_name' => 'Lipa City',
            'barangay_name' => 'Barangay 1',
            'street' => 'Moved Away Road',
        ], $this->home, true);
        $this->buyer->fresh()->forceFill(['first_name' => 'Renamed', 'contact_no' => '09998887777'])->save();

        $this->assertSame('Lipa City', $this->buyer->fresh()->municipality_name);
        $this->assertSame($snapshot, $order->fresh()->shipping_address);

        $this->actingAs($this->seller, 'seller');

        $this->get(route('seller.shipments.label', $sellerOrder))
            ->assertOk()
            ->assertSee('San Fernando')
            ->assertDontSee('Lipa City');

        $this->get(route('seller.orders.show', $sellerOrder))
            ->assertOk()
            ->assertSee('Home Street')
            ->assertSee($snapshot['name'])
            ->assertDontSee('Renamed')
            ->assertDontSee('Moved Away Road');
    }

    public function test_a_new_snapshot_without_a_municipality_code_does_not_borrow_the_profiles(): void
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id, 'shipping_address' => ['municipality' => 'Lipa City', 'municipality_code' => null]]);
        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id, 'seller_id' => $this->seller->id]);

        $this->assertNull($sellerOrder->buyerMunicipalityCode());
    }

    /** @return array<string, array{0: mixed}> */
    public static function badAddressIds(): array
    {
        return [
            'not a number' => ['abc'],
            'a list' => [['1']],
            'does not exist' => [999999],
        ];
    }

    #[DataProvider('badAddressIds')]
    public function test_an_unusable_address_id_places_no_order(mixed $id): void
    {
        $fields = ['address_id' => $id] + $this->formFields($this->openCheckout());

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect()
            ->assertSessionHas('error', BuyerAddressBook::UNAVAILABLE_MESSAGE);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_another_buyers_address_cannot_be_delivered_to(): void
    {
        $stranger = Buyer::factory()->create();
        $theirs = BuyerAddress::query()->create(['buyer_id' => $stranger->id, 'address_mode' => 'manual', 'street' => 'Private Road']);

        $fields = ['address_id' => $theirs->id] + $this->formFields($this->openCheckout());

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect()
            ->assertSessionHas('error', BuyerAddressBook::UNAVAILABLE_MESSAGE);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_address_deleted_after_the_page_loaded_places_no_order(): void
    {
        $fields = $this->formFields($this->openCheckout($this->office));

        $this->office->delete();

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect($this->checkoutUrl())
            ->assertSessionHas('error', BuyerAddressBook::UNAVAILABLE_MESSAGE);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_address_edited_after_the_page_loaded_must_be_reviewed_first(): void
    {
        $fields = $this->formFields($this->openCheckout($this->office));

        $this->office->update(['street' => 'Corrected Avenue']);

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect($this->checkoutUrl($this->office))
            ->assertSessionHas('error');
        $this->assertSame(0, Order::query()->count());

        $this->assertSame('Corrected Avenue', $this->placeOrderTo($this->office)->shipping_address['street']);
    }

    public function test_returning_to_checkout_keeps_the_chosen_address(): void
    {
        $fields = ['shipping_fingerprint' => 'stale'] + $this->formFields($this->openCheckout($this->office));

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect($this->checkoutUrl($this->office));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_address_the_carrier_cannot_reach_disables_place_order_and_is_refused(): void
    {
        $this->assertFalse($this->placeOrderIsDisabled($this->openCheckout()));

        $far = $this->saved('Far Street', false, ['barangay_code' => '0305401002', 'barangay_name' => 'Second']);
        $page = $this->openCheckout($far);

        $this->assertFalse($page->viewData('carrierOptions')[0]['available']);
        $this->assertTrue($this->placeOrderIsDisabled($page));

        $this->post(route('buyer.checkout.store'), $this->formFields($page))->assertRedirect();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_manual_address_without_location_codes_cannot_be_ordered_to(): void
    {
        $manual = $this->saved('Typed Street', false, ['address_mode' => 'manual', 'province_code' => null, 'municipality_code' => null, 'barangay_code' => null]);

        $page = $this->openCheckout($manual);

        $this->assertSame('incomplete', $page->viewData('carrierOptions')[0]['route']);
        $this->assertTrue($this->placeOrderIsDisabled($page));
    }
}
