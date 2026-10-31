<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class SellerVoucherTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 08:00 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->product = $this->productAt(1000.00, 'Silk Scarf');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function productAt(float $price, string $name): Product
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'name' => $name, 'price' => $price, 'stock_quantity' => 20, 'status' => 'active']);
        ProductVariant::query()->where('product_id', $product->id)->update(['price' => $price, 'stock_quantity' => 20, 'is_available' => true]);

        return $product;
    }

    /** @param array<string, mixed> $overrides */
    private function createVoucher(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->seller, 'seller')->post(route('seller.vouchers.store'), $overrides + [
            'code' => 'SAVE10',
            'type' => 'percent',
            'percent' => 10,
            'max_discount' => '50.00',
            'starts_at' => '2026-11-02T10:00',
            'expires_at' => '2026-11-03T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
            'is_active' => '1',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function shopVoucher(array $attributes = []): Voucher
    {
        return Voucher::factory()->shop($this->seller)->create($attributes + [
            'funding_source' => Voucher::FUNDING_SELLER,
            'code' => 'SHOP10',
        ]);
    }

    /** Add the products to a fresh buyer's cart with this voucher applied, and check out. */
    private function checkout(Buyer $buyer, array $lines, string $code): TestResponse
    {
        $ids = [];
        foreach ($lines as [$product, $quantity]) {
            $ids[] = CartItem::factory()->create([
                'buyer_id' => $buyer->id,
                'product_id' => $product->id,
                'variant_id' => ProductVariant::query()->where('product_id', $product->id)->value('id'),
                'quantity' => $quantity,
            ])->id;
        }

        $this->bootstrapCheckoutCarriers($buyer, [$this->seller]);
        $this->actingAs($buyer, 'buyer');
        session([
            CheckoutController::SESSION_KEY => $ids,
            'cart_vouchers' => ['shop:'.$this->seller->id => $code],
        ]);

        return $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ]);
    }

    private function buyer(): Buyer
    {
        return Buyer::factory()->create([
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Diliman',
            'street' => 'Maple Street',
        ]);
    }

    public function test_seller_creates_a_shop_voucher_they_fund(): void
    {
        $this->createVoucher([
            'code' => 'save10',
            'min_spend' => '200.50',
            'usage_limit' => 100,
            'per_buyer_limit' => 2,
            'availability' => 'claimable',
        ])->assertRedirect(route('seller.vouchers.index'))->assertSessionHasNoErrors();

        $voucher = Voucher::sole();
        $this->assertSame('SAVE10', $voucher->code);
        $this->assertSame(VoucherScope::Shop, $voucher->scope);
        $this->assertSame($this->seller->id, $voucher->seller_id);
        $this->assertSame(Voucher::FUNDING_SELLER, $voucher->funding_source);
        $this->assertSame(VoucherType::Percent, $voucher->type);
        $this->assertSame(10, $voucher->value);
        $this->assertSame(5000, $voucher->max_discount);
        $this->assertSame(20050, $voucher->min_spend);
        $this->assertSame(100, $voucher->usage_limit);
        $this->assertSame(2, $voucher->per_buyer_limit);
        $this->assertTrue($voucher->is_claimable);
        $this->assertTrue($voucher->applies_to_all_products);
        $this->assertSame('2026-11-02 02:00:00', $voucher->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_fixed_voucher_stores_centavos_and_has_no_cap(): void
    {
        $this->createVoucher(['type' => 'fixed', 'percent' => null, 'max_discount' => '999.00', 'fixed_amount' => '75.25'])
            ->assertSessionHasNoErrors();

        $voucher = Voucher::sole();
        $this->assertSame(VoucherType::Fixed, $voucher->type);
        $this->assertSame(7525, $voucher->value);
        $this->assertNull($voucher->max_discount);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function codes(): array
    {
        return [
            '4 characters' => ['ABCD', true],
            '12 characters' => ['ABCDEFGH1234', true],
            'lowercase is saved upper' => ['abc1', true],
            'surrounding spaces trimmed' => ['  ABCD  ', true],
            '3 characters' => ['ABC', false],
            '13 characters' => ['ABCDEFGH12345', false],
            'symbol' => ['SAVE-10', false],
            'inner space' => ['SAVE 10', false],
            'accented' => ['SÁVE10', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('codes')]
    public function test_code_is_4_to_12_alphanumerics(string $code, bool $accepted): void
    {
        $response = $this->createVoucher(['code' => $code]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(strtoupper(trim($code)), Voucher::sole()->code);
        } else {
            $response->assertSessionHasErrors('code');
            $this->assertSame(0, Voucher::count());
        }
    }

    public function test_code_is_unique_across_the_whole_platform(): void
    {
        Voucher::factory()->create(['code' => 'TAKEN1']);
        $other = Seller::factory()->create(['status' => 'approved']);
        Voucher::factory()->shop($other)->create(['code' => 'THEIRS']);

        $this->createVoucher(['code' => 'taken1'])->assertSessionHasErrors('code');
        $this->createVoucher(['code' => 'theirs'])->assertSessionHasErrors('code');

        $this->assertSame(2, Voucher::count());
    }

    public function test_voucher_keeps_its_own_code_on_edit(): void
    {
        $voucher = $this->shopVoucher(['starts_at' => now()->addDay(), 'expires_at' => now()->addDays(2), 'type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 5000]);

        $this->actingAs($this->seller, 'seller')->put(route('seller.vouchers.update', $voucher), [
            'code' => 'SHOP10',
            'type' => 'percent',
            'percent' => 20,
            'max_discount' => '50.00',
            'starts_at' => '2026-11-02T10:00',
            'expires_at' => '2026-11-03T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(20, $voucher->fresh()->value);
    }

    public function test_discount_rules(): void
    {
        foreach ([0, 100, '12.5', null] as $percent) {
            $this->createVoucher(['percent' => $percent])->assertSessionHasErrors('percent');
        }

        foreach (['0', '1.234', null] as $cap) {
            $this->createVoucher(['max_discount' => $cap])->assertSessionHasErrors('max_discount');
        }

        foreach (['0', '1.234', null] as $amount) {
            $this->createVoucher(['type' => 'fixed', 'fixed_amount' => $amount])->assertSessionHasErrors('fixed_amount');
        }

        $this->createVoucher(['type' => 'bogus'])->assertSessionHasErrors('type');
        $this->createVoucher(['min_spend' => '-1'])->assertSessionHasErrors('min_spend');
        $this->assertSame(0, Voucher::count());
    }

    public function test_limit_rules(): void
    {
        $this->createVoucher(['usage_limit' => 0])->assertSessionHasErrors('usage_limit');
        $this->createVoucher(['per_buyer_limit' => 0])->assertSessionHasErrors('per_buyer_limit');
        $this->createVoucher(['usage_limit' => 1, 'per_buyer_limit' => 2])->assertSessionHasErrors('per_buyer_limit');
        $this->assertSame(0, Voucher::count());
    }

    public function test_date_rules(): void
    {
        $this->createVoucher(['starts_at' => '2026-11-01T07:59'])->assertSessionHasErrors('starts_at');
        $this->createVoucher(['expires_at' => '2026-11-02T10:00'])->assertSessionHasErrors('expires_at');
        $this->createVoucher(['starts_at' => null])->assertSessionHasErrors('starts_at');
        $this->createVoucher(['expires_at' => null])->assertSessionHasErrors('expires_at');
        $this->assertSame(0, Voucher::count());

        $this->createVoucher(['starts_at' => '2026-11-01T08:00'])->assertSessionHasNoErrors();
    }

    public function test_availability_and_product_scope_rules(): void
    {
        $other = Product::factory()->create(['seller_id' => Seller::factory()->create()->id]);

        $this->createVoucher(['availability' => 'secret'])->assertSessionHasErrors('availability');
        $this->createVoucher(['applies_to' => 'selected'])->assertSessionHasErrors('product_ids');
        $this->createVoucher(['applies_to' => 'selected', 'product_ids' => [$other->id]])->assertSessionHasErrors('product_ids.0');
        $this->assertSame(0, Voucher::count());

        $this->createVoucher(['applies_to' => 'selected', 'product_ids' => [$this->product->id]])->assertSessionHasNoErrors();

        $voucher = Voucher::sole();
        $this->assertFalse($voucher->applies_to_all_products);
        $this->assertSame([$this->product->id], $voucher->products->modelKeys());
    }

    public function test_switching_to_all_products_clears_the_selection(): void
    {
        $voucher = $this->shopVoucher(['starts_at' => now()->addDay(), 'expires_at' => now()->addDays(2), 'type' => VoucherType::Fixed, 'value' => 1000]);
        $voucher->forceFill(['applies_to_all_products' => false])->save();
        $voucher->products()->sync([$this->product->id]);

        $this->actingAs($this->seller, 'seller')->put(route('seller.vouchers.update', $voucher), [
            'code' => 'SHOP10',
            'type' => 'fixed',
            'fixed_amount' => '10.00',
            'starts_at' => '2026-11-02T10:00',
            'expires_at' => '2026-11-03T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($voucher->fresh()->applies_to_all_products);
        $this->assertSame(0, $voucher->products()->count());
    }

    public function test_used_voucher_locks_its_rules_but_can_extend_and_raise(): void
    {
        $voucher = $this->shopVoucher(['times_used' => 3, 'usage_limit' => 10, 'type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 5000, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
        SellerOrder::factory()->create(['seller_id' => $this->seller->id, 'shop_voucher_id' => $voucher->id]);

        $this->actingAs($this->seller, 'seller')->get(route('seller.vouchers.edit', $voucher))
            ->assertOk()
            ->assertSee('You can only extend its end date or raise its limits')
            ->assertDontSee('name="code"', false);

        $this->put(route('seller.vouchers.update', $voucher), [
            'code' => 'HACKED',
            'type' => 'fixed',
            'fixed_amount' => '999.00',
            'usage_limit' => 50,
            'starts_at' => $voucher->starts_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'expires_at' => '2026-12-01T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
            'is_active' => '0',
        ])->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('SHOP10', $voucher->code);
        $this->assertSame(VoucherType::Percent, $voucher->type);
        $this->assertSame(10, $voucher->value);
        $this->assertSame(50, $voucher->usage_limit);
        $this->assertTrue($voucher->is_active);
    }

    public function test_usage_limit_cannot_drop_below_what_was_already_used(): void
    {
        $voucher = $this->shopVoucher(['times_used' => 3, 'type' => VoucherType::Fixed, 'value' => 1000, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay()]);

        $this->actingAs($this->seller, 'seller')->put(route('seller.vouchers.update', $voucher), [
            'code' => 'SHOP10',
            'type' => 'fixed',
            'fixed_amount' => '10.00',
            'usage_limit' => 2,
            'starts_at' => $voucher->starts_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'expires_at' => $voucher->expires_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'availability' => 'code_only',
            'applies_to' => 'all',
        ])->assertSessionHasErrors('usage_limit');
    }

    public function test_only_unused_vouchers_can_be_deleted(): void
    {
        $unused = $this->shopVoucher();
        $used = $this->shopVoucher(['code' => 'USED01']);
        SellerOrder::factory()->create(['seller_id' => $this->seller->id, 'shop_voucher_id' => $used->id]);

        $this->actingAs($this->seller, 'seller');
        $this->delete(route('seller.vouchers.destroy', $used))->assertSessionHasErrors('voucher');
        $this->assertNotNull($used->fresh());

        $this->delete(route('seller.vouchers.destroy', $unused))->assertRedirect(route('seller.vouchers.index'))->assertSessionHasNoErrors();
        $this->assertNull($unused->fresh());
    }

    public function test_other_sellers_and_platform_vouchers_are_off_limits(): void
    {
        $mine = $this->shopVoucher();
        $platform = Voucher::factory()->create(['code' => 'PLATFORM1']);
        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller');

        foreach ([$mine, $platform] as $voucher) {
            $this->get(route('seller.vouchers.edit', $voucher))->assertForbidden();
            $this->put(route('seller.vouchers.update', $voucher), ['code' => 'STOLEN'])->assertForbidden();
            $this->delete(route('seller.vouchers.destroy', $voucher))->assertForbidden();
        }

        $this->get(route('seller.vouchers.index'))->assertOk()->assertDontSee('SHOP10')->assertDontSee('PLATFORM1');
        $this->assertNotNull($mine->fresh());
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('seller.vouchers.index'))->assertRedirect();
        $this->get(route('seller.vouchers.create'))->assertRedirect();
        $this->post(route('seller.vouchers.store'), ['code' => 'NOPE1'])->assertRedirect();
        $this->assertSame(0, Voucher::count());
    }

    public function test_pages_render(): void
    {
        $voucher = $this->shopVoucher(['type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 5000, 'min_spend' => 20000, 'is_claimable' => true, 'starts_at' => now()->addDay(), 'expires_at' => now()->addDays(2)]);
        $this->actingAs($this->seller, 'seller');

        $this->get(route('seller.vouchers.index'))
            ->assertOk()
            ->assertSee(['SHOP10', '10% off', 'Up to ₱50.00', 'Min. spend ₱200.00', 'Claimable', 'Upcoming'], false);
        $this->get(route('seller.vouchers.create'))->assertOk()->assertSee($this->product->name);
        $this->get(route('seller.vouchers.edit', $voucher))->assertOk()->assertSee('name="code"', false);
    }

    public function test_percent_cap_limits_the_discount_at_checkout(): void
    {
        $this->shopVoucher(['type' => VoucherType::Percent, 'value' => 50, 'max_discount' => 10000]);

        $this->checkout($this->buyer(), [[$this->product, 1]], 'SHOP10')->assertRedirect();

        $sellerOrder = OrderItem::query()->sole()->sellerOrder;
        $this->assertSame(10000, $sellerOrder->shop_voucher_discount_minor);
        $this->assertSame(10000, $sellerOrder->seller_funded_discount_minor);
        $this->assertSame(100000 - 10000 + $sellerOrder->shipping_fee_minor, $sellerOrder->payment->amount_minor);
    }

    public function test_voucher_does_nothing_before_it_starts(): void
    {
        $this->shopVoucher(['type' => VoucherType::Fixed, 'value' => 1000, 'starts_at' => now()->addDay()]);

        $this->checkout($this->buyer(), [[$this->product, 1]], 'SHOP10')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'not active yet'));

        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_selected_products_voucher_discounts_only_those_products(): void
    {
        $other = $this->productAt(500.00, 'Plain Belt');
        Voucher::factory()->shop($this->seller)->forProducts([$this->product])->create([
            'code' => 'ONLYSCARF', 'funding_source' => Voucher::FUNDING_SELLER,
            'type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 100000,
        ]);

        $this->checkout($this->buyer(), [[$this->product, 1], [$other, 1]], 'ONLYSCARF')->assertRedirect();

        $this->assertSame(10000, OrderItem::query()->first()->sellerOrder->shop_voucher_discount_minor);
    }

    public function test_selected_products_minimum_spend_ignores_other_products(): void
    {
        $other = $this->productAt(5000.00, 'Expensive Coat');
        Voucher::factory()->shop($this->seller)->forProducts([$this->product])->create([
            'code' => 'ONLYSCARF', 'funding_source' => Voucher::FUNDING_SELLER,
            'type' => VoucherType::Fixed, 'value' => 1000, 'min_spend' => 200000,
        ]);

        $this->checkout($this->buyer(), [[$this->product, 1], [$other, 1]], 'ONLYSCARF')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Spend ₱1,000.00 more to use this voucher.'));

        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_voucher_is_rejected_when_no_selected_product_is_in_the_cart(): void
    {
        $other = $this->productAt(500.00, 'Plain Belt');
        Voucher::factory()->shop($this->seller)->forProducts([$this->product])->create([
            'code' => 'ONLYSCARF', 'funding_source' => Voucher::FUNDING_SELLER, 'type' => VoucherType::Fixed, 'value' => 1000,
        ]);

        $this->checkout($this->buyer(), [[$other, 1]], 'ONLYSCARF')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'eligible items'));

        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_per_buyer_limit_stops_the_same_buyer_but_not_another(): void
    {
        $voucher = $this->shopVoucher(['type' => VoucherType::Fixed, 'value' => 1000, 'per_buyer_limit' => 1]);
        $buyer = $this->buyer();

        $this->checkout($buyer, [[$this->product, 1]], 'SHOP10')->assertRedirect();
        $this->assertSame(1, $voucher->fresh()->times_used);

        $this->checkout($buyer, [[$this->product, 1]], 'SHOP10')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'maximum number of times'));
        $this->assertSame(1, $voucher->fresh()->times_used, 'the same buyer is over the limit');
        $this->assertSame(1, Order::query()->count());

        $this->checkout($this->buyer(), [[$this->product, 1]], 'SHOP10')->assertRedirect();
        $this->assertSame(2, $voucher->fresh()->times_used);
    }

    public function test_cancelled_order_does_not_count_toward_the_per_buyer_limit(): void
    {
        $voucher = $this->shopVoucher(['type' => VoucherType::Fixed, 'value' => 1000, 'per_buyer_limit' => 1]);
        $buyer = $this->buyer();

        $this->checkout($buyer, [[$this->product, 1]], 'SHOP10')->assertRedirect();
        $this->assertSame(1, $voucher->usesByBuyer($buyer->id));

        OrderItem::query()->sole()->sellerOrder->forceFill(['status' => OrderStatus::Cancelled->value])->save();
        $this->assertSame(0, $voucher->usesByBuyer($buyer->id));
    }

    public function test_home_page_never_advertises_a_seller_voucher(): void
    {
        $this->shopVoucher(['code' => 'SECRET1', 'type' => VoucherType::Fixed, 'value' => 1000, 'is_claimable' => true]);
        $this->product->images()->create(['path' => 'products/a.jpg', 'is_primary' => true]);

        $this->get(route('buyer.home'))->assertOk()->assertDontSee('SECRET1');
    }
}
