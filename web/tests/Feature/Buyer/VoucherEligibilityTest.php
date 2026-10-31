<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\VoucherApplication;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoucherEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function cart(): array
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 100, 'stock_quantity' => 10]);
        $item = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($buyer, 'buyer');

        return [$buyer, $product, $item];
    }

    public static function rejectedVouchers(): array
    {
        return [
            'expired' => [['expires_at' => '2020-01-01'], 'This voucher has expired.'],
            'not started' => [['starts_at' => '2099-01-01'], 'This voucher is not active yet.'],
            'limit reached' => [['usage_limit' => 1, 'times_used' => 1], 'This voucher has reached its usage limit.'],
            'one cent short' => [['min_spend' => 10001], 'Spend ₱0.01 more to use this voucher.'],
        ];
    }

    #[DataProvider('rejectedVouchers')]
    public function test_apply_returns_a_specific_rejection_reason(array $attributes, string $message): void
    {
        $this->cart();
        $voucher = Voucher::factory()->fixed(1000)->create($attributes);
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $voucher->code])
            ->assertStatus(422)->assertJsonPath('error', $message)->assertSessionMissing('cart_vouchers');
    }

    public function test_invalid_code_and_already_used_have_distinct_messages(): void
    {
        [$buyer, $product] = $this->cart();
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => 'DOESNOTEXIST'])
            ->assertStatus(422)->assertJsonPath('error', 'Voucher code not found.');
        $voucher = Voucher::factory()->fixed(1000)->create(['per_buyer_limit' => 1]);
        SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id])->id,
            'seller_id' => $product->seller_id, 'status' => OrderStatus::Placed,
            'platform_voucher_id' => $voucher->id,
        ]);
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $voucher->code])
            ->assertStatus(422)->assertJsonPath('error', 'You have already used this voucher the maximum number of times.');
    }

    public function test_voucher_expires_at_its_exact_expiry_time(): void
    {
        $this->freezeTime();
        $this->cart();
        $voucher = Voucher::factory()->fixed(1000)->create(['expires_at' => now()]);
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $voucher->code])
            ->assertStatus(422)->assertJsonPath('error', 'This voucher has expired.');
        $this->assertSame('Expired', $voucher->statusLabel());
    }

    public function test_quantity_reduction_removes_the_voucher_and_reports_the_shortfall(): void
    {
        [, , $item] = $this->cart();
        $item->update(['quantity' => 2]);
        $voucher = Voucher::factory()->fixed(1000)->create(['min_spend' => 10001]);
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $voucher->code])->assertOk()->assertJsonPath('has_voucher', true);
        $this->patchJson(route('buyer.cart.update', $item->id), ['quantity' => 1])
            ->assertOk()->assertJsonPath('has_voucher', false)
            ->assertJsonPath('voucher_notice', 'Spend ₱0.01 more to use this voucher.')
            ->assertJsonPath('discount_raw', 0)->assertJsonPath('total', '₱100.00')
            ->assertSessionMissing('cart_vouchers')->assertSessionMissing('cart_voucher');
    }

    public function test_percentage_caps_apply_to_shop_platform_and_shipping_independently(): void
    {
        [$buyer, $product] = $this->cart();
        $base = new CartSummary($buyer->cartItems()->with('product.seller', 'variant')->get());
        $shop = Voucher::factory()->shop($product->seller_id)->percent(50)->create(['max_discount' => 1000]);
        $platform = Voucher::factory()->platform()->percent(50)->create(['max_discount' => 2000]);
        $shipping = Voucher::factory()->shipping()->percent(50)->create(['max_discount' => 300]);
        $application = new VoucherApplication(collect([$shop, $platform, $shipping]), $base->sellerGroups, [$product->seller_id => 1000]);
        $this->assertSame(1000, $application->shopDiscountCentavos);
        $this->assertSame(2000, $application->platformDiscountCentavos);
        $this->assertSame(300, $application->shippingDiscountCentavos);
        $summary = new CartSummary($buyer->cartItems()->with('product.seller', 'variant')->get(), $application);
        $this->assertSame(7000, $summary->totalCentavos);
    }

    public function test_oversized_vouchers_cannot_make_shipping_or_merchandise_negative(): void
    {
        [$buyer, $product] = $this->cart();
        $items = $buyer->cartItems()->with('product.seller', 'variant')->get();
        $base = new CartSummary($items);
        $shop = Voucher::factory()->shop($product->seller_id)->fixed(999999)->create();
        $platform = Voucher::factory()->platform()->fixed(999999)->create();
        $shipping = Voucher::factory()->shipping()->fixed(999999)->create();
        $application = new VoucherApplication(collect([$shop, $platform, $shipping]), $base->sellerGroups, [$product->seller_id => 1000]);
        $summary = new CartSummary($items, $application);
        $this->assertSame(0, $summary->totalCentavos);
        $this->assertSame(10000, $application->shopDiscountCentavos);
        $this->assertSame(0, $application->platformDiscountCentavos);
        $this->assertSame(1000, $application->shippingDiscountCentavos);
        $this->assertSame(0, $application->shippingFeeFor($product->seller_id) - $application->shippingDiscountFor($product->seller_id));

        $withoutShipping = new VoucherApplication(collect([$shipping]), $base->sellerGroups);
        $this->assertSame(0, $withoutShipping->shippingDiscountCentavos);
        $this->assertSame(10000, (new CartSummary($items, $withoutShipping))->totalCentavos);
    }
}
