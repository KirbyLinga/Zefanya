<?php

namespace Tests\Feature\Buyer;

use App\Enums\VoucherType;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Buyer\VoucherSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/** BUY-CHK-002 to 005, 008 and 010. */
class CheckoutShippingTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->buyer = Buyer::factory()->create(['street' => 'Maple Street', 'municipality_name' => 'San Fernando']);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);

        // After the bootstrap, which zeroes the per-kilo rates for money tests.
        config(['buyer.shipping_rates' => [
            'same_municipality' => ['first_kg_minor' => 4900, 'additional_kg_minor' => 1500],
            'same_province' => ['first_kg_minor' => 6900, 'additional_kg_minor' => 2000],
        ]]);

        $this->actingAs($this->buyer, 'buyer');
    }

    /** @return array{0: Product, 1: CartItem} */
    private function line(int $weightGrams, int $quantity = 1, float $price = 100.00, ?Seller $seller = null): array
    {
        $seller ??= $this->seller;
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => $price, 'stock_quantity' => 50, 'status' => 'active', 'weight_grams' => $weightGrams]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $variant->forceFill(['price' => $price, 'stock_quantity' => 50, 'is_available' => true, 'weight_grams' => null])->save();

        return [$product, CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ])];
    }

    /** @param list<CartItem> $lines */
    private function openCheckout(array $lines): TestResponse
    {
        return $this->get(route('buyer.checkout.index', ['items' => array_map(fn (CartItem $line) => $line->id, $lines)]))->assertOk();
    }

    /** Everything the rendered page would submit, so these tests act like a real form. */
    private function formFields(TestResponse $page): array
    {
        $fields = [];

        foreach (['checkout_token', 'price_fingerprint', 'shipping_fingerprint', 'voucher_fingerprint'] as $name) {
            $this->assertSame(1, preg_match('/name="'.$name.'" value="([^"]*)"/', $page->getContent(), $match), $name);
            $fields[$name] = $match[1];
        }

        return $fields + $this->checkoutCarrierPayload([$this->seller]);
    }

    /** The real attribute, not Tailwind's disabled:opacity-40 class on the same button. */
    private function placeOrderIsDisabled(TestResponse $page): bool
    {
        $this->assertSame(1, preg_match('/<button[^>]*data-checkout-place-order[^>]*>/s', $page->getContent(), $button));

        return preg_match('/\sdisabled(?=[\s>=])/', $button[0]) === 1;
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function weights(): array
    {
        return [
            'no weight recorded' => [0, 1, 4900],
            'light parcel' => [250, 1, 4900],
            'exactly one kilo' => [1000, 1, 4900],
            'one gram over' => [1001, 1, 6400],
            'exactly two kilos' => [2000, 1, 6400],
            'quantity pushes it over' => [600, 2, 6400],
            'heavy parcel' => [4500, 1, 10900],
        ];
    }

    #[DataProvider('weights')]
    public function test_chk_fee_uses_the_weight_rounded_up_to_whole_kilos(int $grams, int $quantity, int $fee): void
    {
        [, $line] = $this->line($grams, $quantity);

        $options = $this->openCheckout([$line])->viewData('carrierOptions');

        $this->assertSame($fee, $options[0]['carriers'][0]['fee_minor']);
    }

    public function test_chk_a_different_municipality_uses_its_own_rates(): void
    {
        $this->buyer->forceFill(['municipality_code' => '030540200'])->save();
        [, $line] = $this->line(2500);

        $options = $this->openCheckout([$line])->viewData('carrierOptions');

        $this->assertSame(6900 + 2 * 2000, $options[0]['carriers'][0]['fee_minor']);
        $this->assertSame(3, $options[0]['chargeable_kg']);
    }

    public function test_chk_each_store_is_charged_on_its_own_weight(): void
    {
        $other = Seller::factory()->create(['status' => 'approved']);
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller, $other]);
        config(['buyer.shipping_rates.same_municipality.additional_kg_minor' => 1500]);
        [, $light] = $this->line(500);
        [, $heavy] = $this->line(3000, seller: $other);

        $page = $this->openCheckout([$light, $heavy]);
        $fees = collect($page->viewData('carrierOptions'))->mapWithKeys(fn (array $row) => [$row['seller_id'] => $row['carriers'][0]['fee_minor']]);

        $this->assertSame(4900, $fees[$this->seller->id]);
        $this->assertSame(4900 + 2 * 1500, $fees[$other->id]);
        $page->assertSee('Charged for 3 kg');

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            'carriers' => [$this->seller->id => $carrier->id, $other->id => $carrier->id],
        ])->assertRedirect();

        $this->assertSame(4900, SellerOrder::query()->where('seller_id', $this->seller->id)->sole()->shipping_fee_minor);
        $this->assertSame(7900, SellerOrder::query()->where('seller_id', $other->id)->sole()->shipping_fee_minor);
    }

    public function test_chk_the_order_records_the_fee_and_weight_it_was_quoted_on(): void
    {
        [, $line] = $this->line(1500, 2);
        $page = $this->openCheckout([$line]);

        $this->post(route('buyer.checkout.store'), $this->formFields($page))->assertRedirect();

        $parcel = SellerOrder::query()->sole();
        $this->assertSame(4900 + 2 * 1500, $parcel->shipping_fee_minor);
        $this->assertSame(3000, $parcel->parcel_weight_grams);
        $this->assertSame(20000 + 7900, Order::query()->sole()->total_minor);
    }

    public function test_chk_free_shipping_still_beats_the_rate_table(): void
    {
        config(['buyer.announcements.free_shipping_threshold_minor' => 15000]);
        [, $line] = $this->line(9000, 1, 200.00);

        $this->assertSame(0, $this->openCheckout([$line])->viewData('carrierOptions')[0]['carriers'][0]['fee_minor']);
    }

    public function test_chk_cross_province_adds_its_per_kilo_rate_only_when_configured(): void
    {
        $service = new CheckoutCarrierService;
        $fee = new \ReflectionMethod($service, 'crossProvinceFeeMinor');
        $carrier = LogisticsProvider::factory()->create();

        config(['buyer.cross_province' => ['fee_minor' => 12000, 'additional_kg_minor' => 3000, 'free_shipping_threshold_minor' => null]]);
        $this->assertSame(12000, $fee->invoke($service, $carrier, 10000, 1000));
        $this->assertSame(12000 + 2 * 3000, $fee->invoke($service, $carrier, 10000, 2100));

        config(['buyer.cross_province' => ['fee_minor' => 12000, 'additional_kg_minor' => 0, 'free_shipping_threshold_minor' => null]]);
        $this->assertSame(12000, $fee->invoke($service, $carrier, 10000, 2100));

        config(['buyer.cross_province' => ['fee_minor' => null, 'additional_kg_minor' => 3000, 'free_shipping_threshold_minor' => null]]);
        $this->assertNull($fee->invoke($service, $carrier, 10000, 2100));
    }

    public function test_chk_an_unserviceable_address_disables_place_order_and_the_server_agrees(): void
    {
        [, $line] = $this->line(500);
        $this->buyer->forceFill(['barangay_code' => null])->save();

        $page = $this->openCheckout([$line]);

        $this->assertFalse($page->viewData('carriersAvailable'));
        $this->assertTrue($this->placeOrderIsDisabled($page));
        $page->assertSee('Complete both the seller pickup address and your delivery address');

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ])->assertRedirect()->assertSessionHasErrors();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_chk_a_barangay_no_carrier_covers_disables_place_order(): void
    {
        [, $line] = $this->line(500);
        $this->buyer->forceFill(['barangay_code' => '0305401002'])->save();

        $page = $this->openCheckout([$line]);

        $this->assertFalse($page->viewData('carriersAvailable'));
        $this->assertTrue($this->placeOrderIsDisabled($page));
    }

    public function test_chk_a_serviceable_address_leaves_place_order_enabled(): void
    {
        [, $line] = $this->line(500);

        $this->assertFalse($this->placeOrderIsDisabled($this->openCheckout([$line])));
    }

    public function test_chk_note_is_saved_for_its_own_store_and_shown_to_that_seller_escaped(): void
    {
        $other = Seller::factory()->create(['status' => 'approved']);
        $carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller, $other]);
        [, $first] = $this->line(500);
        [, $second] = $this->line(500, seller: $other);
        $this->openCheckout([$first, $second])
            ->assertSee('name="notes['.$this->seller->id.']"', false)
            ->assertSee('name="notes['.$other->id.']"', false)
            ->assertSee('maxlength="200"', false);

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            'carriers' => [$this->seller->id => $carrier->id, $other->id => $carrier->id],
            'notes' => [$this->seller->id => '  Please <b>gift</b> wrap  ', $other->id => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $mine = SellerOrder::query()->where('seller_id', $this->seller->id)->sole();
        $theirs = SellerOrder::query()->where('seller_id', $other->id)->sole();
        $this->assertSame('Please <b>gift</b> wrap', $mine->buyer_note);
        $this->assertNull($theirs->buyer_note);

        $this->actingAs($this->seller, 'seller')->get(route('seller.orders.show', $mine))->assertOk()
            ->assertSee('Note from buyer')
            ->assertSee('Please &lt;b&gt;gift&lt;/b&gt; wrap', false)
            ->assertDontSee('<b>gift</b>', false);
        $this->actingAs($other, 'seller')->get(route('seller.orders.show', $theirs))->assertOk()->assertDontSee('Note from buyer');

        // The seller is told, not just able to find it: notification and order list.
        $this->assertStringContainsString('Note from buyer: Please <b>gift</b> wrap', $this->seller->notifications()->sole()->data['message']);
        $this->assertStringNotContainsString('Note from buyer', $other->notifications()->sole()->data['message']);

        $this->actingAs($this->seller, 'seller')->get(route('seller.notifications.index'))->assertOk()
            ->assertSee('Note from buyer: Please &lt;b&gt;gift&lt;/b&gt; wrap', false)
            ->assertDontSee('<b>gift</b>', false);
        $this->get(route('seller.dashboard'))->assertOk()
            ->assertSee('Note from buyer: Please &lt;b&gt;gift&lt;/b&gt; wrap', false);
        $this->get(route('seller.orders.index'))->assertOk()
            ->assertSee('Note: Please &lt;b&gt;gift&lt;/b&gt; wrap', false)
            ->assertDontSee('<b>gift</b>', false);
        $this->actingAs($other, 'seller')->get(route('seller.orders.index'))->assertOk()->assertDontSee('data-buyer-note', false);
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function notes(): array
    {
        return [
            '200 characters' => [str_repeat('a', 200), true],
            '201 characters' => [str_repeat('a', 201), false],
            'not text' => [['nested'], false],
        ];
    }

    #[DataProvider('notes')]
    public function test_chk_note_length_rule(mixed $note, bool $accepted): void
    {
        [, $line] = $this->line(500);
        session([CheckoutController::SESSION_KEY => [$line->id]]);

        $response = $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
            'notes' => [$this->seller->id => $note],
        ]);

        if ($accepted) {
            $this->assertSame($note, SellerOrder::query()->sole()->buyer_note);
        } else {
            $response->assertSessionHasErrors('notes.'.$this->seller->id);
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_chk_a_rejected_note_is_kept_on_the_page(): void
    {
        [, $line] = $this->line(500);
        $page = $this->openCheckout([$line]);
        $long = str_repeat('a', 201);

        $this->from(route('buyer.checkout.index', ['items' => [$line->id]]))
            ->followingRedirects()
            ->post(route('buyer.checkout.store'), $this->formFields($page) + ['notes' => [$this->seller->id => $long]])
            ->assertOk()
            ->assertSee('up to 200 characters')
            ->assertSee($long);
    }

    public function test_chk_the_real_page_form_places_the_order_when_nothing_changed(): void
    {
        [, $line] = $this->line(500);
        $page = $this->openCheckout([$line]);

        $this->post(route('buyer.checkout.store'), $this->formFields($page))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame(1, Order::query()->count());
    }

    public function test_chk_a_product_price_change_returns_to_checkout_with_the_new_total(): void
    {
        [$product, $line] = $this->line(500);
        $page = $this->openCheckout([$line]);
        $fields = $this->formFields($page);

        ProductVariant::query()->where('product_id', $product->id)->update(['price' => 250.00]);

        $return = route('buyer.checkout.index', ['items' => [$line->id]]);
        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect($return)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'price changed'));

        $this->assertSame(0, Order::query()->count());
        $this->assertFalse(CheckoutToken::query()->where('token', $fields['checkout_token'])->sole()->used);

        $confirmed = $this->get($return)->assertOk()->assertSee('A product price changed')->assertSee('₱299.00');
        $this->post(route('buyer.checkout.store'), $this->formFields($confirmed))->assertRedirect();

        $this->assertSame(29900, Order::query()->sole()->total_minor);
    }

    public function test_chk_a_shipping_rate_change_preserves_the_rate_of_an_open_checkout(): void
    {
        [, $line] = $this->line(500);
        $fields = $this->formFields($this->openCheckout([$line]));

        config(['buyer.shipping_rates.same_municipality.first_kg_minor' => 7500]);

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(4900, SellerOrder::query()->sole()->shipping_fee_minor);
    }

    public function test_chk_a_voucher_that_expires_during_checkout_is_caught_at_place_order(): void
    {
        [, $line] = $this->line(500);
        $voucher = Voucher::factory()->fixed(1000)->create(['code' => 'TENOFF']);
        session([VoucherSessionService::SESSION_KEY => ['platform' => 'TENOFF']]);
        $page = $this->openCheckout([$line])->assertSee('TENOFF');
        $fields = $this->formFields($page);

        $voucher->forceFill(['expires_at' => now()->subMinute()])->save();

        $return = route('buyer.checkout.index', ['items' => [$line->id]]);
        $this->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect($return)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'expired') && str_contains($message, 'place your order again'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $voucher->fresh()->times_used);

        // The page now shows the full price, and that is what gets ordered.
        $confirmed = $this->get($return)->assertOk()->assertSee('₱149.00');
        $this->post(route('buyer.checkout.store'), $this->formFields($confirmed))->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(14900, $order->total_minor);
    }

    public function test_chk_a_voucher_used_up_by_someone_else_is_caught_at_place_order(): void
    {
        [, $line] = $this->line(500);
        $voucher = Voucher::factory()->fixed(1000)->create(['code' => 'LASTONE', 'usage_limit' => 1, 'times_used' => 0]);
        session([VoucherSessionService::SESSION_KEY => ['platform' => 'LASTONE']]);
        $fields = $this->formFields($this->openCheckout([$line]));

        $voucher->forceFill(['times_used' => 1])->save();

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'usage limit'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_chk_a_voucher_whose_discount_changed_is_caught_at_place_order(): void
    {
        [, $line] = $this->line(500);
        $voucher = Voucher::factory()->create(['code' => 'CHANGES', 'type' => VoucherType::Fixed, 'value' => 5000]);
        session([VoucherSessionService::SESSION_KEY => ['platform' => 'CHANGES']]);
        $fields = $this->formFields($this->openCheckout([$line]));

        $voucher->forceFill(['value' => 1000])->save();

        $this->post(route('buyer.checkout.store'), $fields)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'voucher on this order changed'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_chk_a_still_valid_voucher_is_used_once_at_place_order(): void
    {
        [, $line] = $this->line(500);
        $voucher = Voucher::factory()->fixed(1000)->create(['code' => 'GOOD']);
        session([VoucherSessionService::SESSION_KEY => ['platform' => 'GOOD']]);
        $fields = $this->formFields($this->openCheckout([$line]));

        $this->post(route('buyer.checkout.store'), $fields)->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1000, Order::query()->sole()->discount_minor);
        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    public function test_adding_a_delivery_zone_enables_checkout_only_after_validated_coverage_exists(): void
    {
        [, $line] = $this->line(500);
        $this->buyer->forceFill(['barangay_code' => '0305401002', 'barangay_name' => 'Second'])->save();
        $blocked = $this->openCheckout([$line]);
        $this->assertFalse($blocked->viewData('carriersAvailable'));
        $this->assertTrue($this->placeOrderIsDisabled($blocked));
        $fields = $this->formFields($blocked);
        $this->post(route('buyer.checkout.store'), $fields)->assertSessionHasErrors('carriers.'.$this->seller->id);
        $this->assertSame(0, Order::count());
        $this->assertFalse(CheckoutToken::where('token', $fields['checkout_token'])->sole()->used);

        $station = LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->firstOrFail();
        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.shipping-rates.index'))->assertOk()->assertSee('Add delivery zone');
        $this->post(route('logistics.areas.store'), [
            'station_hub_id' => $station->id, 'city_municipality_code' => '030540100',
            'barangay_codes' => ['0305401002'],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('areas', [
            'logistics_provider_id' => $this->checkoutCarrier->id,
            'station_hub_id' => $station->id, 'barangay_code' => '0305401002', 'is_active' => true,
        ]);
        $this->actingAs($this->buyer->fresh(), 'buyer');
        $enabled = $this->openCheckout([$line]);
        $this->assertTrue($enabled->viewData('carriersAvailable'));
        $this->assertFalse($this->placeOrderIsDisabled($enabled));
        $this->post(route('buyer.checkout.store'), $this->formFields($enabled))->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    public function test_a_zone_disabled_after_checkout_starts_cannot_be_bypassed_by_a_frozen_rate(): void
    {
        [, $line] = $this->line(500);
        $fields = $this->formFields($this->openCheckout([$line]));
        Area::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('barangay_code', $this->buyer->barangay_code)->update(['is_active' => false]);
        $this->post(route('buyer.checkout.store'), $fields)->assertSessionHasErrors('carriers.'.$this->seller->id);
        $this->assertSame(0, Order::count());
        $this->assertFalse(CheckoutToken::where('token', $fields['checkout_token'])->sole()->used);
    }
}
