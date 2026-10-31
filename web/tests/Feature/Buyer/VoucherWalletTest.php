<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoucherWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function offer(array $attributes = []): Voucher
    {
        return Voucher::factory()->shop(Seller::factory()->create(['status' => 'approved']))->create($attributes + [
            'is_claimable' => true, 'is_active' => true, 'expires_at' => now()->addDay(),
            'usage_limit' => 10, 'times_used' => 0,
        ]);
    }

    public function test_store_and_product_claim_buttons_become_wallet_links_after_claiming(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $voucher = $this->offer();
        $product = Product::factory()->create(['seller_id' => $voucher->seller_id]);
        $pages = [route('buyer.stores.show', $voucher->seller_id), route('buyer.products.show', $product)];
        $claimUrl = route('buyer.vouchers.claim', $voucher);
        $this->actingAs($buyer, 'buyer');

        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertSee('action="'.$claimUrl.'"', false)->assertSee('Claim');
        }
        $this->from($pages[0])->post($claimUrl)->assertRedirect($pages[0])
            ->assertSessionHas('success', "{$voucher->code} is in your vouchers.");
        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertDontSee('action="'.$claimUrl.'"', false)
                ->assertSee('In your vouchers')->assertSee(route('buyer.vouchers.index'), false);
        }
        $this->get(route('buyer.vouchers.index'))->assertOk()->assertSee('My Vouchers')
            ->assertSee($voucher->code)->assertSee('Ready to use');
    }

    public function test_repeated_claims_keep_one_wallet_entry_per_buyer_and_do_not_consume_usage(): void
    {
        $voucher = $this->offer();
        $first = Buyer::factory()->create();
        $second = Buyer::factory()->create();
        foreach ([$first, $second] as $buyer) {
            $this->actingAs($buyer, 'buyer')->post(route('buyer.vouchers.claim', $voucher))->assertRedirect();
            $original = $voucher->claims()->where('buyer_id', $buyer->id)->sole();
            $this->post(route('buyer.vouchers.claim', $voucher))->assertRedirect();
            $this->assertSame($original->id, $voucher->claims()->where('buyer_id', $buyer->id)->sole()->id);
        }
        $this->assertSame(2, $voucher->claims()->count());
        $this->assertSame(0, $voucher->fresh()->times_used);
    }

    public function test_wallet_lists_only_the_signed_in_buyers_claims(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $own = $this->offer(['code' => 'MYWALLET']);
        $foreign = $this->offer(['code' => 'OTHERWALLET']);
        $own->claims()->create(['buyer_id' => $buyer->id]);
        $foreign->claims()->create(['buyer_id' => Buyer::factory()->create()->id]);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.vouchers.index'))->assertOk()
            ->assertSee('MYWALLET')->assertDontSee('OTHERWALLET');
    }

    public static function unavailableOffers(): array
    {
        return [
            'code only' => [['is_claimable' => false]],
            'inactive' => [['is_active' => false]],
            'future' => [['starts_at' => '2099-01-01 00:00:00']],
            'expired' => [['expires_at' => '2000-01-01 00:00:00']],
            'fully used' => [['usage_limit' => 1, 'times_used' => 1]],
        ];
    }

    #[DataProvider('unavailableOffers')]
    public function test_unavailable_vouchers_cannot_be_claimed_by_posting_directly(array $attributes): void
    {
        $voucher = $this->offer($attributes);
        $this->actingAs(Buyer::factory()->create(), 'buyer')->post(route('buyer.vouchers.claim', $voucher))->assertNotFound();
        $this->assertSame(0, $voucher->claims()->count());
    }

    public function test_guests_cannot_claim_or_access_a_wallet(): void
    {
        $voucher = $this->offer();
        $this->postJson(route('buyer.vouchers.claim', $voucher))->assertUnauthorized();
        $this->getJson(route('buyer.vouchers.index'))->assertUnauthorized();
        $this->assertSame(0, $voucher->claims()->count());
    }
}
