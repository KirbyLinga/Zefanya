<?php

namespace Tests\Feature\Api;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/vouchers` — the vouchers a buyer may choose from.
 *
 * Two things are being pinned here, and the second is the one worth reading.
 *
 * 1. **Only usable vouchers are listed.** Inactive, expired and used-up vouchers
 *    are absent, because a picker that offers something the cart will refuse is
 *    worse than a shorter list.
 * 2. **`value` has two scales and `type` says which.** `vouchers.value` is
 *    centavos for a fixed voucher and a whole percentage for a percent one — a
 *    real quirk of this schema, not an accident of the resource. Flattening it
 *    into one unit (saying everything is centavos) would make a 10%-off voucher
 *    into a ₱0.10 one, so the tests below check both scales explicitly.
 *
 * What is *not* here: any cap or category scope. The app's own `Voucher` model
 * carries a `maxDiscount` and a category, and its pricing engine computes with
 * them, but the server has neither — inventing a cap here would be a money number
 * the two sides disagree about, which is why the app's mapper refuses to offer
 * what it cannot represent. See `docs/api-contract.md` OPEN QUESTIONS §0.1/§0.2.
 */
class VoucherApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_lists_an_active_voucher_with_its_facts(): void
    {
        Voucher::factory()->fixed(20000)->minSpend(50000)->create(['code' => 'ZEFANYANEW']);

        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['code', 'type', 'value', 'min_spend_minor', 'expires_at']]])
            ->assertJsonPath('data.0.code', 'ZEFANYANEW')
            ->assertJsonPath('data.0.type', 'fixed')
            // ₱200.00 in centavos, and ₱500.00 as the minimum spend.
            ->assertJsonPath('data.0.value', 20000)
            ->assertJsonPath('data.0.min_spend_minor', 50000);
    }

    public function test_a_percent_voucher_reports_a_whole_percentage_not_centavos(): void
    {
        Voucher::factory()->percent(10)->create(['code' => 'WELCOME10']);

        // Not 1000: a percentage is stored as the number 10, and the type field is
        // what tells a reader which scale it is looking at.
        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.type', 'percent')
            ->assertJsonPath('data.0.value', 10);
    }

    public function test_a_voucher_with_no_minimum_spend_or_expiry_says_so_with_nulls(): void
    {
        Voucher::factory()->fixed(5000)->create();

        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.min_spend_minor', null)
            ->assertJsonPath('data.0.expires_at', null);
    }

    public function test_an_inactive_voucher_is_not_offered(): void
    {
        Voucher::factory()->inactive()->create();

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_expired_voucher_is_not_offered(): void
    {
        Voucher::factory()->expired()->create();

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_voucher_at_its_usage_limit_is_not_offered(): void
    {
        Voucher::factory()->create(['usage_limit' => 3, 'times_used' => 3]);

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_voucher_under_its_usage_limit_is_offered(): void
    {
        Voucher::factory()->create(['usage_limit' => 3, 'times_used' => 2]);

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_voucher_expiring_later_today_is_still_offered(): void
    {
        Voucher::factory()->create(['expires_at' => now()->addHour()]);

        // The date boundary is the one an off-by-one hides behind: 'expires_at' on
        // a timestamp is a moment, not a day, so a voucher that ends in an hour is
        // valid now and must not be filtered out by a date-only comparison.
        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_needs_no_token(): void
    {
        $this->getJson(route('api.vouchers.index'))->assertOk();
    }
}
