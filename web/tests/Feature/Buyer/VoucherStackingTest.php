<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\VoucherApplication;
use App\Services\Buyer\VoucherSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class VoucherStackingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function cart(): array
    {
        $buyer = Buyer::factory()->create();
        $sellers = Seller::factory()->count(2)->create(['status' => 'approved']);
        foreach ($sellers as $seller) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => 1000, 'stock_quantity' => 10]);
            CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }
        $this->actingAs($buyer, 'buyer');

        return [$buyer, $sellers];
    }

    private function offers($sellers): array
    {
        return [
            Voucher::factory()->shop($sellers[0])->fixed(1000)->create(),
            Voucher::factory()->shop($sellers[1])->fixed(2000)->create(),
            Voucher::factory()->platform()->fixed(3000)->create(),
            Voucher::factory()->shipping()->fixed(4000)->create(),
        ];
    }

    public function test_applying_a_second_voucher_replaces_only_its_slot(): void
    {
        [, $sellers] = $this->cart();
        $offers = $this->offers($sellers);
        foreach ($offers as $index => $voucher) {
            $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $voucher->code])
                ->assertOk()->assertJsonCount($index + 1, 'vouchers');
        }

        foreach ($offers as $index => $old) {
            $replacement = Voucher::factory()->fixed(5000)->create([
                'scope' => $old->scope, 'seller_id' => $old->seller_id,
            ]);
            $offers[$index] = $replacement;
            $response = $this->postJson(route('buyer.cart.voucher.apply'), ['code' => strtolower($replacement->code)])
                ->assertOk()->assertJsonCount(4, 'vouchers');
            $expected = collect($offers)->mapWithKeys(fn ($voucher) => [$voucher->slotKey() => $voucher->code])->all();
            $actual = collect($response->json('vouchers'))->pluck('code', 'slot')->all();
            $this->assertEquals($expected, $actual);
            $response->assertSessionHas(VoucherSessionService::SESSION_KEY, $expected);
        }
        $this->getJson(route('buyer.cart.summary'))->assertOk()->assertJsonCount(4, 'vouchers');
    }

    public function test_engine_keeps_the_latest_voucher_per_slot_in_totals_and_serialization(): void
    {
        [$buyer, $sellers] = $this->cart();
        $old = $this->offers($sellers);
        $latest = collect($old)->map(fn ($voucher) => Voucher::factory()->fixed(5000)->create([
            'scope' => $voucher->scope, 'seller_id' => $voucher->seller_id,
        ]));
        $summary = new CartSummary($buyer->cartItems()->with('product.seller', 'variant')->get());
        $application = new VoucherApplication(collect($old)->concat($latest), $summary->sellerGroups, [
            $sellers[0]->id => 5000, $sellers[1]->id => 5000,
        ]);
        $this->assertSame($latest->pluck('id')->all(), $application->voucherIds());
        $this->assertCount(4, $application->toArray());
        $this->assertSame(10000, $application->shopDiscountCentavos);
        $this->assertSame(5000, $application->platformDiscountCentavos);
        $this->assertSame(5000, $application->shippingDiscountCentavos);
    }

    public function test_restored_list_with_duplicate_slots_keeps_the_latest_vouchers(): void
    {
        [, $sellers] = $this->cart();
        $old = $this->offers($sellers);
        $newPlatform = Voucher::factory()->platform()->fixed(1000)->create();
        $newShop = Voucher::factory()->shop($sellers[0])->fixed(1000)->create();
        $newShipping = Voucher::factory()->shipping()->fixed(1000)->create();
        $this->withSession(['cart_vouchers' => collect($old)->pluck('code')->concat([
            $newPlatform->code, $newShop->code, $newShipping->code,
        ])->all()])->getJson(route('buyer.cart.summary'))->assertOk()->assertJsonCount(4, 'vouchers')
            ->assertSessionHas('cart_vouchers.platform', $newPlatform->code)
            ->assertSessionHas('cart_vouchers.shop:'.$sellers[0]->id, $newShop->code)
            ->assertSessionHas('cart_vouchers.shipping', $newShipping->code);
    }

    public function test_invalid_replacement_or_wrong_slot_keeps_existing_vouchers(): void
    {
        [, $sellers] = $this->cart();
        $platform = Voucher::factory()->platform()->fixed(1000)->create();
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $platform->code])->assertOk();
        $invalid = Voucher::factory()->platform()->inactive()->create();
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $invalid->code])->assertStatus(422);
        $shop = Voucher::factory()->shop($sellers[0])->create();
        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => $shop->code, 'slot' => 'platform'])->assertStatus(422);
        $this->getJson(route('buyer.cart.summary'))->assertOk()->assertJsonCount(1, 'vouchers')
            ->assertJsonPath('vouchers.0.code', $platform->code);
    }

    public function test_explicit_platform_slot_takes_precedence_over_an_outdated_legacy_code(): void
    {
        $this->cart();
        $old = Voucher::factory()->platform()->fixed(1000)->create();
        $new = Voucher::factory()->platform()->fixed(2000)->create();
        $this->withSession(['cart_voucher' => $old->code, 'cart_vouchers' => ['platform' => $new->code]])
            ->getJson(route('buyer.cart.summary'))->assertOk()->assertJsonCount(1, 'vouchers')
            ->assertJsonPath('vouchers.0.code', $new->code)->assertSessionHas('cart_voucher', $new->code);
    }
}
