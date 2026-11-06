<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\VoucherType;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Models\Shared\VoucherClaim;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** SEL-VOU-009/010: rules lock once claimed; ending clears unused wallets. */
class SellerVoucherLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Buyer $buyer;

    private Product $product;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 08:00 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->buyer = Buyer::factory()->create();
        $this->product = Product::factory()->create(['seller_id' => $this->seller->id, 'status' => 'active', 'price' => 1000.00, 'stock_quantity' => 10]);
        ProductVariant::query()->where('product_id', $this->product->id)->update(['price' => 1000.00, 'stock_quantity' => 10, 'is_available' => true]);
        $this->voucher = Voucher::factory()->shop($this->seller)->create([
            'code' => 'LOCKME',
            'funding_source' => Voucher::FUNDING_SELLER,
            'is_claimable' => true,
            'type' => VoucherType::Percent,
            'value' => 10,
            'max_discount' => 5000,
            'min_spend' => 10000,
            'usage_limit' => 10,
            'per_buyer_limit' => 2,
            'starts_at' => now()->subDay(),
            'expires_at' => Carbon::parse('2026-11-10 02:00:00', 'UTC'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function claim(?Buyer $buyer = null): TestResponse
    {
        return $this->actingAs($buyer ?? $this->buyer, 'buyer')->post(route('buyer.vouchers.claim', $this->voucher));
    }

    /** @param array<string, mixed> $overrides */
    private function update(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->seller, 'seller')->put(route('seller.vouchers.update', $this->voucher), $overrides + [
            'expires_at' => '2026-11-10T10:00',
            'usage_limit' => 10,
            'per_buyer_limit' => 2,
        ]);
    }

    private function end(): TestResponse
    {
        return $this->actingAs($this->seller, 'seller')->post(route('seller.vouchers.end', $this->voucher));
    }

    public function test_buyer_claims_once_and_sees_it_in_their_wallet(): void
    {
        $this->claim()->assertRedirect()->assertSessionHasNoErrors();
        $this->claim()->assertRedirect();

        $this->assertSame(1, VoucherClaim::count());

        $this->get(route('buyer.vouchers.index'))
            ->assertOk()
            ->assertSee('LOCKME')
            ->assertSee('Ready to use')
            ->assertSee($this->seller->business_name);
    }

    public function test_only_vouchers_on_offer_can_be_claimed(): void
    {
        $codeOnly = Voucher::factory()->shop($this->seller)->create(['code' => 'CODEONLY', 'is_claimable' => false]);
        $expired = Voucher::factory()->shop($this->seller)->create(['code' => 'EXPIRED', 'is_claimable' => true, 'expires_at' => now()->subDay()]);
        $this->actingAs($this->buyer, 'buyer');

        $this->post(route('buyer.vouchers.claim', $codeOnly))->assertNotFound();
        $this->post(route('buyer.vouchers.claim', $expired))->assertNotFound();

        $this->assertSame(0, VoucherClaim::count());
    }

    public function test_guests_cannot_claim_and_only_see_the_code(): void
    {
        $this->post(route('buyer.vouchers.claim', $this->voucher))->assertRedirect();
        $this->assertSame(0, VoucherClaim::count());

        $this->get(route('buyer.stores.show', ['seller' => $this->seller]))->assertOk()->assertSee('LOCKME')->assertDontSee('>Claim<', false);
    }

    public function test_offer_shows_claim_then_in_your_vouchers(): void
    {
        $this->actingAs($this->buyer, 'buyer');
        $page = route('buyer.products.show', $this->product);

        $this->get($page)->assertOk()->assertSee('action="'.route('buyer.vouchers.claim', $this->voucher).'"', false);

        $this->claim();

        $this->get($page)->assertOk()->assertSee('In your vouchers')->assertDontSee('action="'.route('buyer.vouchers.claim', $this->voucher).'"', false);
    }

    public function test_applying_a_listed_voucher_in_the_cart_claims_it_and_code_only_does_not(): void
    {
        $item = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => ProductVariant::query()->where('product_id', $this->product->id)->value('id'),
            'quantity' => 1,
        ]);
        $this->actingAs($this->buyer, 'buyer');

        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => 'LOCKME', 'selected_ids' => [$item->id]])->assertOk();
        $this->assertTrue($this->voucher->fresh()->rulesLocked());

        $this->voucher->forceFill(['is_claimable' => false])->save();
        VoucherClaim::query()->delete();

        $this->postJson(route('buyer.cart.voucher.apply'), ['code' => 'LOCKME', 'selected_ids' => [$item->id]])->assertOk();
        $this->assertSame(0, VoucherClaim::count());
    }

    public function test_unclaimed_voucher_stays_fully_editable(): void
    {
        $this->assertFalse($this->voucher->rulesLocked());

        $this->actingAs($this->seller, 'seller')->put(route('seller.vouchers.update', $this->voucher), [
            'code' => 'RENAMED',
            'type' => 'fixed',
            'fixed_amount' => '25.00',
            'usage_limit' => 1,
            'per_buyer_limit' => 1,
            'starts_at' => '2026-11-02T10:00',
            'expires_at' => '2026-11-03T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
        ])->assertSessionHasNoErrors();

        $this->assertSame('RENAMED', $this->voucher->fresh()->code);
        $this->assertSame(1, $this->voucher->fresh()->usage_limit);
    }

    public function test_claimed_voucher_can_extend_the_end_and_raise_limits(): void
    {
        $this->claim();

        $this->update(['expires_at' => '2026-12-01T10:00', 'usage_limit' => 50, 'per_buyer_limit' => 5])->assertSessionHasNoErrors();

        $voucher = $this->voucher->fresh();
        $this->assertSame('2026-12-01 02:00:00', $voucher->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(50, $voucher->usage_limit);
        $this->assertSame(5, $voucher->per_buyer_limit);
    }

    public function test_claimed_voucher_can_remove_a_limit_because_that_raises_it(): void
    {
        $this->claim();

        $this->update(['usage_limit' => null, 'per_buyer_limit' => null])->assertSessionHasNoErrors();

        $this->assertNull($this->voucher->fresh()->usage_limit);
        $this->assertNull($this->voucher->fresh()->per_buyer_limit);
    }

    public function test_claimed_voucher_cannot_shorten_or_lower(): void
    {
        $this->claim();

        $this->update(['expires_at' => '2026-11-09T10:00'])->assertSessionHasErrors('expires_at');
        $this->update(['usage_limit' => 9])->assertSessionHasErrors('usage_limit');
        $this->update(['per_buyer_limit' => 1])->assertSessionHasErrors('per_buyer_limit');

        $voucher = $this->voucher->fresh();
        $this->assertSame('2026-11-10 02:00:00', $voucher->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(10, $voucher->usage_limit);
        $this->assertSame(2, $voucher->per_buyer_limit);
    }

    public function test_claimed_voucher_with_no_limit_cannot_gain_one(): void
    {
        $this->voucher->forceFill(['usage_limit' => null])->save();
        $this->claim();

        $this->update(['usage_limit' => 1000])->assertSessionHasErrors('usage_limit');

        $this->assertNull($this->voucher->fresh()->usage_limit);
    }

    public function test_claimed_voucher_ignores_every_other_field(): void
    {
        $this->claim();
        $other = Product::factory()->create(['seller_id' => $this->seller->id]);

        $this->update([
            'code' => 'HACKED',
            'type' => 'fixed',
            'fixed_amount' => '999.00',
            'percent' => 99,
            'max_discount' => '999.00',
            'min_spend' => '0',
            'starts_at' => '2027-01-01T10:00',
            'availability' => 'code_only',
            'applies_to' => 'selected',
            'product_ids' => [$other->id],
            'is_active' => '0',
        ])->assertSessionHasNoErrors();

        $voucher = $this->voucher->fresh();
        $this->assertSame('LOCKME', $voucher->code);
        $this->assertSame(VoucherType::Percent, $voucher->type);
        $this->assertSame(10, $voucher->value);
        $this->assertSame(5000, $voucher->max_discount);
        $this->assertSame(10000, $voucher->min_spend);
        $this->assertTrue($voucher->is_claimable);
        $this->assertTrue($voucher->is_active);
        $this->assertTrue($voucher->applies_to_all_products);
        $this->assertSame(0, $voucher->products()->count());
        $this->assertTrue($voucher->starts_at->lt(now()));
    }

    public function test_locked_edit_page_shows_only_the_end_date_and_limits(): void
    {
        $this->claim();

        $this->actingAs($this->seller, 'seller')->get(route('seller.vouchers.edit', $this->voucher))
            ->assertOk()
            ->assertSee('You can only extend its end date or raise its limits')
            ->assertSee('name="expires_at"', false)
            ->assertSee('name="usage_limit"', false)
            ->assertSee('name="per_buyer_limit"', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="min_spend"', false)
            ->assertDontSee('name="starts_at"', false)
            ->assertDontSee('name="is_active"', false)
            ->assertSee('End voucher');
    }

    public function test_claimed_voucher_cannot_be_deleted(): void
    {
        $this->claim();

        $this->actingAs($this->seller, 'seller')
            ->delete(route('seller.vouchers.destroy', $this->voucher))
            ->assertSessionHasErrors('voucher');

        $this->assertNotNull($this->voucher->fresh());
    }

    public function test_ending_removes_the_voucher_from_unused_wallets_only(): void
    {
        $unused = Buyer::factory()->create();
        $cancelledOnly = Buyer::factory()->create();
        $this->claim($unused);
        $this->claim($cancelledOnly);
        $this->claim($this->buyer);

        SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
            'shop_voucher_id' => $this->voucher->id,
        ]);
        SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $cancelledOnly->id])->id,
            'seller_id' => $this->seller->id,
            'shop_voucher_id' => $this->voucher->id,
            'status' => OrderStatus::Cancelled,
        ]);

        $this->end()->assertRedirect(route('seller.vouchers.index'))->assertSessionHasNoErrors();

        $this->assertSame([$this->buyer->id], VoucherClaim::query()->pluck('buyer_id')->all());

        $this->actingAs($unused, 'buyer')->get(route('buyer.vouchers.index'))->assertOk()->assertDontSee('LOCKME');
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.vouchers.index'))->assertOk()->assertSee('LOCKME')->assertSee('Used');
    }

    public function test_ended_voucher_stops_working_and_is_final(): void
    {
        $this->claim();
        $this->end();

        $voucher = $this->voucher->fresh();
        $this->assertTrue($voucher->hasEnded());
        $this->assertFalse($voucher->is_active);
        $this->assertSame('Ended', $voucher->statusLabel());
        $this->assertSame('This voucher has ended.', $voucher->rejectionReason(100000));
        $this->assertTrue($voucher->expires_at->lte(now()));
        $this->assertSame([], Voucher::offeredBy([$this->seller->id])->pluck('code')->all());

        $this->claim()->assertNotFound();

        $this->actingAs($this->seller, 'seller');
        $this->get(route('seller.vouchers.edit', $this->voucher))->assertRedirect(route('seller.vouchers.index'));
        $this->update(['expires_at' => '2026-12-01T10:00', 'usage_limit' => 50])->assertSessionHasErrors('voucher');
        $this->end()->assertSessionHasErrors('voucher');

        $this->assertFalse($this->voucher->fresh()->is_active);
    }

    public function test_ended_voucher_left_only_in_unused_wallets_can_be_deleted(): void
    {
        $this->claim();
        $this->end();

        $this->actingAs($this->seller, 'seller')
            ->delete(route('seller.vouchers.destroy', $this->voucher))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->voucher->fresh());
    }

    public function test_an_expired_voucher_stays_in_the_wallet_and_can_be_extended(): void
    {
        $this->claim();

        Carbon::setTestNow(Carbon::parse('2026-11-11 00:00:00', 'UTC'));

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.vouchers.index'))->assertOk()->assertSee('Expired');

        $this->update(['expires_at' => '2026-11-20T10:00'])->assertSessionHasNoErrors();

        $this->assertSame('Active', $this->voucher->fresh()->statusLabel());
    }

    public function test_other_sellers_cannot_end_a_voucher(): void
    {
        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller')
            ->post(route('seller.vouchers.end', $this->voucher))
            ->assertForbidden();

        $this->assertFalse($this->voucher->fresh()->hasEnded());
    }

    public function test_seller_list_shows_end_only_while_it_can_still_end(): void
    {
        $this->actingAs($this->seller, 'seller');
        $action = 'action="'.route('seller.vouchers.end', $this->voucher).'"';

        $this->get(route('seller.vouchers.index'))->assertOk()->assertSee($action, false);

        $this->end();

        $this->get(route('seller.vouchers.index'))->assertOk()->assertSee('Ended')->assertDontSee($action, false);
    }
}
