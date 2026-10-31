<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin dashboard summary strip.
 *
 * The stats must come from real queries — the dashboard previously showed a
 * broken `status = 'pending'` count (always 0, because the real statuses are
 * unverified / pending / approved / rejected) plus two
 * hardcoded figures ("128" and "₱12,450") that had no backing table at all.
 */
class AdminDashboardStatsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Stats Admin',
            'email' => 'stats.admin.'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    private function dashboard()
    {
        return $this->actingAs($this->admin, 'admin')
            ->get(route('admin.dashboard'));
    }

    /**
     * Seed a known mix of account statuses across all three role tables.
     *
     * Pending (awaiting admin decision) = 5 buyers + 2 sellers + 1 logistics = 8
     * Approved (active accounts)        = 3 buyers + 2 sellers + 1 logistics = 6
     */
    private function seedKnownAccounts(): void
    {
        foreach (range(1, 5) as $i) {
            Buyer::factory()->create(['status' => 'pending', 'approved_at' => null]);
        }
        Buyer::factory()->create(['status' => 'unverified', 'email_verified_at' => null, 'approved_at' => null]);
        foreach (range(1, 3) as $i) {
            Buyer::factory()->create(['status' => 'approved']);
        }
        foreach (range(1, 2) as $i) {
            Buyer::factory()->create(['status' => 'rejected']);
        }

        foreach (range(1, 2) as $i) {
            Seller::factory()->pending()->create();
        }
        foreach (range(1, 2) as $i) {
            Seller::factory()->create(['status' => 'approved']);
        }

        LogisticsProvider::factory()->pending()->create();
        LogisticsProvider::factory()->create(['status' => 'approved']);
    }

    public function test_pending_registrations_counts_pending_across_every_role(): void
    {
        $this->seedKnownAccounts();

        // 8, not 9: the unverified buyer and the two rejected buyers
        // are NOT awaiting an admin decision, so they must not be counted.
        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder(['Pending Registrations', '>8</span>'], false);
    }

    public function test_active_users_counts_approved_accounts_across_every_role(): void
    {
        $this->seedKnownAccounts();

        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder(['Active Users', '>6</span>'], false);
    }

    public function test_dashboard_shows_no_fabricated_stats(): void
    {
        $this->seedKnownAccounts();

        $response = $this->dashboard()->assertOk();

        // The two hardcoded figures from the mockup must be gone.
        $response->assertDontSee('>128</span>', false);
        $response->assertDontSee('12,450');

        // Nothing in dispute and nothing finalized in this data: real zeros.
        $response
            ->assertSeeInOrder(['Open Disputes', '>0</span>'], false)
            ->assertSeeInOrder(['Commission (This Month)', '>₱0.00</span>'], false);
    }

    private function parcelReturn(array $attributes = []): ParcelReturn
    {
        $parcel = SellerOrder::factory()->create();

        return ParcelReturn::query()->forceCreate($attributes + [
            'seller_order_id' => $parcel->id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged',
            'requested_at' => now(),
        ]);
    }

    private function row(string $label): array
    {
        return collect($this->dashboard()->viewData('attention'))->firstWhere('label', $label);
    }

    public function test_the_action_list_counts_real_work_and_keeps_empty_queues_visible(): void
    {
        Buyer::factory()->count(2)->create(['status' => 'pending', 'approved_at' => null]);
        Seller::factory()->pending()->create();
        $this->parcelReturn(['status' => ParcelReturnStatus::InDispute->value]);
        $this->parcelReturn(['financial_review_required' => true]);

        $this->dashboard()->assertOk()
            ->assertSee('Needs your action')
            ->assertSee('2 buyers, 1 seller')
            ->assertSee('Incident reports')
            ->assertSee(route('admin.incidents.index'), false)
            ->assertViewHas('openDisputes', 1);

        $this->assertSame(3, $this->row('Registrations to review')['count']);
        $this->assertSame(1, $this->row('Escalated return disputes')['count']);
        $this->assertSame(1, $this->row('Refunds to record')['count']);
        $this->assertSame(0, $this->row('Incident reports')['count']);
    }

    public function test_a_recorded_refund_leaves_the_count(): void
    {
        $this->parcelReturn(['financial_review_required' => true, 'refunded_at' => now(), 'refund_reference' => 'GCASH-1']);

        $this->assertSame(0, $this->row('Refunds to record')['count']);
    }

    public function test_seller_payouts_link_to_their_page(): void
    {
        SellerPayoutRequest::query()->forceCreate([
            'seller_id' => Seller::factory()->create()->id,
            'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => 5000,
            'status' => SellerPayoutRequest::PENDING,
        ]);

        $this->assertSame(1, $this->row('Seller payout requests')['count']);
        $this->assertSame(route('admin.seller-payouts.index'), $this->row('Seller payout requests')['url']);
        $this->dashboard()->assertSee('Process');
    }

    public function test_platform_money_uses_philippine_months_and_only_marketplace_funded_vouchers(): void
    {
        // 00:30 on Nov 1 in Manila is still Oct 31 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-31 16:30:00', 'UTC'));
        $order = fn (array $values) => SellerOrder::factory()->status(OrderStatus::Completed)->create()->forceFill($values)->save();

        $order(['commission_minor' => 2250, 'commission_finalized_at' => now(), 'platform_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE, 'platform_voucher_discount_minor' => 3000, 'shipping_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE, 'shipping_voucher_discount_minor' => 10000]);
        $order(['commission_minor' => 500, 'commission_finalized_at' => now(), 'platform_voucher_funding_source' => Voucher::FUNDING_SELLER, 'platform_voucher_discount_minor' => 7777]);
        $order(['commission_minor' => 9000, 'commission_finalized_at' => Carbon::parse('2026-10-31 15:50:00', 'UTC'), 'platform_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE, 'platform_voucher_discount_minor' => 8888]);

        $this->dashboard()->assertOk()
            ->assertViewHas('money', ['commission' => 2750, 'platformVouchers' => 3000, 'shippingVouchers' => 10000, 'net' => -10250])
            ->assertSee('Platform money this month')
            ->assertSee('−₱102.50')
            ->assertSee('November 2026');

        Carbon::setTestNow();
    }

    public function test_settlements_are_this_months_cod_less_shipping_per_provider(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 04:00:00', 'UTC'));
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'business_name' => 'Bilis Hatid Express']);
        $remit = function (int $cod, int $shipping, string $status, string $at) use ($provider): void {
            $parcel = SellerOrder::factory()->create(['shipping_fee_minor' => $shipping]);
            DB::table('cod_remittances')->insert([
                'payment_id' => Payment::factory()->create(['seller_order_id' => $parcel->id])->id,
                'seller_order_id' => $parcel->id,
                'courier_id' => Courier::factory()->create()->id,
                'logistics_provider_id' => $provider->id,
                'amount_minor' => $cod,
                'status' => $status,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        };
        $remit(30000, 5000, CodRemittance::REMITTED_TO_LOGISTICS, '2026-10-05 04:00:00');
        $remit(15000, 0, CodRemittance::PENDING_REMITTANCE, '2026-10-06 04:00:00');
        $remit(99900, 100, CodRemittance::REMITTED_TO_LOGISTICS, '2026-09-20 04:00:00');

        $this->dashboard()->assertOk()
            ->assertViewHas('settlements', fn ($rows) => $rows->all() === [[
                'provider' => 'Bilis Hatid Express', 'codMinor' => 45000, 'shippingMinor' => 5000, 'receivedMinor' => 0, 'owedMinor' => 40000, 'withRiders' => 1,
            ]])
            ->assertSeeInOrder(['Logistics settlements', 'Bilis Hatid Express', '₱450.00', '₱50.00', '₱400.00', '1 with riders']);

        Carbon::setTestNow();
    }

    public function test_an_empty_settlement_table_says_so(): void
    {
        $this->dashboard()->assertOk()->assertSee('No cash on delivery collected this month.');
    }

    public function test_a_moderator_gets_the_work_list_but_no_money(): void
    {
        Buyer::factory()->create(['status' => 'pending', 'approved_at' => null]);
        $this->parcelReturn(['financial_review_required' => true]);
        $moderator = Admin::factory()->create(['role' => 'moderator']);

        $this->actingAs($moderator, 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Registrations to review')
            ->assertSee('Marketplace activity')
            ->assertDontSee('Commission (This Month)')
            ->assertDontSee('Platform money this month')
            ->assertDontSee('Logistics settlements')
            ->assertDontSee('Refunds to record')
            ->assertDontSee('Seller payout requests')
            ->assertViewHas('money', null);
    }

    public function test_support_sees_figures_with_no_links_it_cannot_open(): void
    {
        Buyer::factory()->create(['status' => 'pending', 'approved_at' => null]);
        $support = Admin::factory()->create(['role' => 'support']);

        $this->actingAs($support, 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Pending Registrations')
            ->assertDontSee('Needs your action')
            ->assertDontSee(route('admin.registrations.index'), false)
            ->assertDontSee(route('admin.users.index'), false);
    }

    public function test_the_top_bar_shows_the_signed_in_admin_and_no_dead_controls(): void
    {
        $admin = Admin::factory()->create(['role' => 'moderator', 'name' => 'Rhea Santos']);

        $content = $this->actingAs($admin, 'admin')->get(route('admin.users.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<header data-admin-topbar.*?<\/header>/s', $content, $bar));

        $this->assertStringContainsString('Rhea Santos', $bar[0]);
        $this->assertStringContainsString('Moderator', $bar[0]);
        $this->assertStringContainsString(route('admin.two-factor.show'), $bar[0]);
        $this->assertStringContainsString(route('admin.logout'), $bar[0]);
        $this->assertStringNotContainsString('Super Admin', $bar[0]);
        $this->assertStringNotContainsString('Search accounts', $bar[0]);
        $this->assertStringNotContainsString('data-lucide="bell"', $bar[0]);
        $this->assertStringNotContainsString('data-lucide="help-circle"', $bar[0]);
    }
}
