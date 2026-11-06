<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\VoucherApplication;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class PlatformVoucherAllocationTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function cart(array $subtotals): CartSummary
    {
        $buyer = Buyer::factory()->create();
        foreach ($subtotals as $subtotal) {
            $seller = Seller::factory()->create(['status' => 'approved']);
            $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => number_format($subtotal / 100, 2, '.', ''), 'stock_quantity' => 10]);
            CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }

        return new CartSummary($buyer->cartItems()->with('product.seller', 'variant')->orderBy('id')->get());
    }

    public static function allocations(): array
    {
        return [
            'largest subtotal rather than largest fraction' => [[100, 99, 1], 2, [2, 0, 0]],
            'all remainder goes to largest store' => [[100, 99, 98], 2, [2, 0, 0]],
            'unequal stores' => [[10000, 20000], 1001, [333, 668]],
            'stable equal-subtotal tie' => [[101, 101, 98], 1, [1, 0, 0]],
            'full discount' => [[10000, 20000], 30000, [10000, 20000]],
        ];
    }

    #[DataProvider('allocations')]
    public function test_platform_shares_use_subtotals_with_remainder_on_the_largest_store(array $subtotals, int $discount, array $expected): void
    {
        $base = $this->cart($subtotals);
        $platform = Voucher::factory()->platform()->fixed($discount)->create();
        $application = new VoucherApplication(collect([$platform]), $base->sellerGroups);
        $this->assertSame($expected, array_values($application->platformDiscounts));
        $this->assertSame($discount, array_sum($application->platformDiscounts));
        $reversed = new VoucherApplication(collect([$platform]), $base->sellerGroups->reverse()->values());
        $this->assertEquals($application->platformDiscounts, $reversed->platformDiscounts);
    }

    public function test_shop_discounts_do_not_change_platform_subtotal_weights(): void
    {
        $base = $this->cart([20000, 10000]);
        $sellerId = $base->sellerGroups[0]['seller']->id;
        $shop = Voucher::factory()->shop($sellerId)->fixed(10000)->create();
        $platform = Voucher::factory()->platform()->fixed(3000)->create();
        $application = new VoucherApplication(collect([$shop, $platform]), $base->sellerGroups);
        $this->assertSame([2000, 1000], array_values($application->platformDiscounts));
        $this->assertSame(13000, $application->discountCentavos);
    }

    public function test_remainder_skips_a_store_whose_merchandise_is_already_fully_discounted(): void
    {
        $base = $this->cart([20000, 10000]);
        $sellerId = $base->sellerGroups[0]['seller']->id;
        $shop = Voucher::factory()->shop($sellerId)->fixed(20000)->create();
        $platform = Voucher::factory()->platform()->fixed(3000)->create();
        $application = new VoucherApplication(collect([$shop, $platform]), $base->sellerGroups);
        $this->assertSame([0, 3000], array_values($application->platformDiscounts));
        $this->assertSame(23000, $application->discountCentavos);
    }

    public function test_shipping_allocation_keeps_its_existing_fractional_remainder_rule(): void
    {
        $base = $this->cart([100, 99, 1]);
        $fees = array_combine($base->sellerGroups->map(fn ($group) => $group['seller']->id)->all(), [100, 99, 1]);
        $shipping = Voucher::factory()->shipping()->fixed(2)->create();
        $application = new VoucherApplication(collect([$shipping]), $base->sellerGroups, $fees);
        $this->assertSame([1, 1, 0], array_values($application->shippingDiscounts));
    }

    public function test_checkout_persists_the_same_platform_shares_on_each_seller_order(): void
    {
        $base = $this->cart([10000, 9900, 100]);
        $buyer = Buyer::findOrFail($base->sellerGroups[0]['items'][0]->buyer_id);
        $sellers = $base->sellerGroups->pluck('seller');
        $this->bootstrapCheckoutCarriers($buyer, $sellers);
        $voucher = Voucher::factory()->platform()->fixed(2)->create(['funding_source' => Voucher::FUNDING_MARKETPLACE]);
        $token = CheckoutToken::issue($buyer->id);
        $this->actingAs($buyer, 'buyer')->withSession([
            'checkout_item_ids' => $buyer->cartItems()->pluck('id')->all(),
            'cart_vouchers' => ['platform' => $voucher->code],
        ])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token,
            ...$this->checkoutCarrierPayload($sellers),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $orders = SellerOrder::query()->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))->orderBy('seller_id')->get();
        $this->assertCount(3, $orders);
        $this->assertSame([2, 0, 0], $orders->pluck('platform_voucher_discount_minor')->all());
        $this->assertSame(2, $orders->sum('platform_voucher_discount_minor'));
    }
}
