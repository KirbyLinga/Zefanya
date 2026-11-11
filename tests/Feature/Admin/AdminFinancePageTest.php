<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentMethod;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinancePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    // 12:00 on Nov 10, 2026 in Manila.
    private const NOW = '2026-11-10 04:00:00';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->admin = Admin::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin, 'admin')->get(route('admin.finance.index', $query));
    }

    private function finalizedOrder(array $values): SellerOrder
    {
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create();
        $order->forceFill($values)->save();

        return $order;
    }

    /** One of every ledger type in November, plus decoys outside the month or not yet final. */
    private function seedLedger(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'Tindahan ni Aling Nena']);
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'business_name' => 'Bilis Hatid Express']);

        // COD money is counted through the logistics remittance, never per payment.
        Payment::factory()->paid()->create(['method' => PaymentMethod::Cod->value, 'amount_minor' => 99900]);

        $remittance = fn (string $status, ?Carbon $confirmedAt, int $amount) => LogisticsRemittance::query()->forceCreate([
            'logistics_provider_id' => $provider->id, 'parcel_count' => 3, 'cod_total_minor' => $amount + 6000, 'shipping_total_minor' => 6000,
            'amount_minor' => $amount, 'reference' => 'BDO-'.Str::random(6), 'status' => $status,
            'received_amount_minor' => $confirmedAt ? $amount : null, 'confirmed_at' => $confirmedAt,
        ]);
        $remittance(LogisticsRemittance::CONFIRMED, now(), 120000);
        $remittance(LogisticsRemittance::SUBMITTED, null, 88800);

        SellerPayoutRequest::query()->forceCreate([
            'seller_id' => $seller->id, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 40000, 'debt_deducted_minor' => 5000,
            'status' => SellerPayoutRequest::PAID, 'paid_at' => now(), 'payout_reference' => 'GCASH-PAYOUT-9', 'payout_destination' => 'GCash 0917',
        ]);
        SellerPayoutRequest::query()->forceCreate([
            'seller_id' => $seller->id, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 12300, 'status' => SellerPayoutRequest::PENDING,
        ]);

        $returned = SellerOrder::factory()->create(['seller_id' => $seller->id, 'tracking_number' => 'ZEF-RETURN-1']);
        ParcelReturn::query()->forceCreate([
            'seller_order_id' => $returned->id, 'status' => ParcelReturnStatus::Requested->value, 'reason' => 'Item arrived damaged',
            'requested_at' => now(), 'refunded_at' => now(), 'refund_reference' => 'GCASH-REFUND-1', 'refund_amount_minor' => 15000,
        ]);

        $debtOrder = SellerOrder::factory()->create(['seller_id' => $seller->id, 'tracking_number' => 'ZEF-DEBT-1']);
        $payment = Payment::factory()->create(['seller_order_id' => $debtOrder->id]);
        $codId = DB::table('cod_remittances')->insertGetId([
            'payment_id' => $payment->id, 'seller_order_id' => $debtOrder->id, 'courier_id' => Courier::factory()->create()->id,
            'logistics_provider_id' => $provider->id, 'amount_minor' => 10000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $settlementId = DB::table('seller_settlements')->insertGetId([
            'seller_order_id' => $debtOrder->id, 'cod_remittance_id' => $codId, 'payment_id' => $payment->id, 'seller_id' => $seller->id,
            'collected_amount_minor' => 10000, 'shipping_allocation_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seller_debts')->insert([
            'seller_id' => $seller->id, 'seller_order_id' => $debtOrder->id, 'seller_settlement_id' => $settlementId,
            'amount_minor' => 7000, 'settled_minor' => 2000, 'reason' => 'return_after_payout', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_admins_who_handle_money_can_open_it(): void
    {
        $this->get(route('admin.finance.index'))->assertRedirect(route('admin.login'));

        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->get(route('admin.finance.index'))->assertOk();
        }
        foreach (['moderator', 'support'] as $role) {
            $staff = Admin::factory()->create(['role' => $role]);
            $this->actingAs($staff, 'admin')->get(route('admin.finance.index'))->assertForbidden();
            $this->actingAs($staff, 'admin')->get(route('admin.finance.export'))->assertForbidden();
        }
    }

    public function test_the_sidebar_links_to_finance(): void
    {
        $this->page()->assertOk()
            ->assertSee(route('admin.finance.index'), false)
            ->assertDontSee('Finance: coming soon');
    }

    public function test_earnings_match_the_dashboard_and_use_philippine_months(): void
    {
        $this->finalizedOrder(['subtotal_minor' => 100000, 'shipping_fee_minor' => 6000, 'seller_net_minor' => 95500, 'commission_minor' => 4500, 'commission_finalized_at' => now(),
            'platform_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE, 'platform_voucher_discount_minor' => 1000]);
        $this->finalizedOrder(['subtotal_minor' => 20000, 'shipping_fee_minor' => 0, 'seller_net_minor' => 19100, 'commission_minor' => 900, 'commission_finalized_at' => now(),
            'shipping_voucher_funding_source' => Voucher::FUNDING_SELLER, 'shipping_voucher_discount_minor' => 5000]);
        // 07:50 on Nov 1 in Manila, but Oct 31 in UTC: November.
        $this->finalizedOrder(['subtotal_minor' => 10000, 'commission_minor' => 300, 'commission_finalized_at' => Carbon::parse('2026-10-31 23:50:00', 'UTC')]);
        // 23:50 on Oct 31 in Manila: October.
        $this->finalizedOrder(['subtotal_minor' => 99999, 'commission_minor' => 7000, 'commission_finalized_at' => Carbon::parse('2026-10-31 15:50:00', 'UTC')]);

        $this->page()->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['finalizedOrders'] === 3
                && $summary['itemSalesMinor'] === 130000
                && $summary['shippingFeesMinor'] === 6000
                && $summary['commissionMinor'] === 5700
                && $summary['platformVoucherMinor'] === 1000
                && $summary['shippingVoucherMinor'] === 0
                && $summary['netMinor'] === 4700)
            ->assertSee('₱47.00');

        $this->actingAs($this->admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('money', fn (array $money) => $money['net'] === 4700);

        // Oct 31 in Manila belongs to October, on this page and on the dashboard.
        $this->page(['month' => '2026-10'])->assertViewHas('summary', fn (array $summary) => $summary['commissionMinor'] === 7000);
        $this->actingAs($this->admin, 'admin')->get(route('admin.dashboard', ['month' => '2026-10']))
            ->assertViewHas('money', fn (array $money) => $money['commission'] === 7000);
    }

    public function test_bad_or_future_months_fall_back_to_this_month(): void
    {
        $this->finalizedOrder(['commission_minor' => 1200, 'commission_finalized_at' => now()]);

        foreach (['2027-01', 'garbage', '2026-13'] as $bad) {
            $this->page(['month' => $bad])->assertOk()
                ->assertViewHas('period', fn (Carbon $period) => $period->format('Y-m') === '2026-11')
                ->assertViewHas('summary', fn (array $summary) => $summary['commissionMinor'] === 1200);
        }
    }

    public function test_the_trend_covers_twelve_months_ending_at_the_picked_month(): void
    {
        $this->finalizedOrder(['commission_minor' => 4000, 'commission_finalized_at' => Carbon::parse('2026-09-15 04:00:00', 'UTC')]);

        $this->page(['month' => '2026-10'])->assertOk()->assertViewHas('trend', function (array $trend) {
            $byMonth = collect($trend)->keyBy('value');

            return count($trend) === 12 && $trend[11]['value'] === '2026-10' && $trend[0]['value'] === '2025-11'
                && $byMonth['2026-09']['commissionMinor'] === 4000 && $byMonth['2026-10']['commissionMinor'] === 0;
        });
    }

    public function test_payment_methods_group_finalized_orders(): void
    {
        $cod = $this->finalizedOrder(['commission_minor' => 0, 'commission_finalized_at' => now()]);
        $second = $this->finalizedOrder(['commission_minor' => 0, 'commission_finalized_at' => now()]);
        $lastMonth = $this->finalizedOrder(['commission_minor' => 0, 'commission_finalized_at' => Carbon::parse('2026-10-05 04:00:00', 'UTC')]);
        Payment::factory()->paid()->create(['seller_order_id' => $cod->id, 'method' => PaymentMethod::Cod->value, 'amount_minor' => 25000]);
        Payment::factory()->paid()->create(['seller_order_id' => $second->id, 'method' => PaymentMethod::Cod->value, 'amount_minor' => 10000]);
        Payment::factory()->paid()->create(['seller_order_id' => $lastMonth->id, 'method' => PaymentMethod::Cod->value, 'amount_minor' => 77700]);

        $this->page()->assertViewHas('methods', function (array $methods) {
            $byMethod = collect($methods)->keyBy('method');

            return $byMethod['cod']['orders'] === 2 && $byMethod['cod']['amountMinor'] === 35000;
        });
    }

    public function test_the_ledger_lists_only_money_that_moved_through_zefanya_this_month(): void
    {
        $this->seedLedger();

        $response = $this->page()->assertOk();
        $response->assertViewHas('entries', function ($entries) {
            $byType = collect($entries->items())->keyBy('type');

            return $entries->total() === 4 && ! $byType->has('online_payment')
                && $byType['logistics_remittance']['amountMinor'] === 120000 && $byType['logistics_remittance']['party'] === 'Bilis Hatid Express'
                && $byType['seller_payout']['amountMinor'] === 35000 && $byType['seller_payout']['status'] === 'Paid · debt deducted'
                && $byType['return_refund']['amountMinor'] === 15000 && $byType['return_refund']['party'] === 'Buyer'
                && $byType['seller_debt']['status'] === 'Open · ₱50.00 left';
        });
        $response->assertViewHas('totals', [
            'in' => 120000,
            'out' => 50000,
            'owed_by_zefanya' => 0,
            'owed_to_zefanya' => 7000,
        ]);
        // The seller's payout account is never shown, only the reference.
        $response->assertDontSee('GCash 0917')->assertSee('GCASH-PAYOUT-9');
    }

    public function test_a_reversed_logistics_share_counts_as_zero(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'business_name' => 'Agila Cargo']);
        $payoutId = DB::table('origin_share_payouts')->insertGetId([
            'logistics_provider_id' => $provider->id, 'parcel_count' => 2, 'amount_minor' => 4800, 'reference' => 'BPI-ORIGIN-1',
            'paid_on' => '2026-11-03', 'status' => 'PAID', 'paid_by_admin_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->page()->assertViewHas('totals', fn (array $totals) => $totals['out'] === 4800);

        DB::table('operations_audit_logs')->insert([
            'actor_type' => 'admin', 'actor_id' => $this->admin->id, 'actor_name' => 'Finance Admin', 'logistics_provider_id' => $provider->id,
            'entity_type' => 'origin_share_payout', 'entity_id' => $payoutId, 'entity_label' => 'BPI-ORIGIN-1',
            'action' => 'origin_share_reversed', 'reason' => 'Wrong amount', 'created_at' => now(),
        ]);

        $this->page()->assertViewHas('totals', fn (array $totals) => $totals['out'] === 0)->assertSee('Reversed (was ₱48.00)');
    }

    public function test_the_type_filter_narrows_the_ledger_and_ignores_unknown_types(): void
    {
        $this->seedLedger();

        $this->page(['type' => 'seller_payout'])->assertOk()
            ->assertViewHas('type', 'seller_payout')
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 1)
            // Totals always cover the whole month.
            ->assertViewHas('totals', fn (array $totals) => $totals['in'] === 120000);

        $this->page(['type' => 'drop table'])->assertOk()
            ->assertViewHas('type', null)
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 4);
    }

    public function test_an_empty_month_says_so(): void
    {
        $this->page()->assertOk()->assertSee('No money movements in November 2026.');
    }

    public function test_the_csv_export_has_the_summary_and_ledger_and_neutralises_formulas(): void
    {
        $this->seedLedger();
        Seller::query()->update(['business_name' => '=HYPERLINK("http://evil.test")']);

        $response = $this->actingAs($this->admin, 'admin')->get(route('admin.finance.export', ['month' => '2026-11']));
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('zefanya-finance-2026-11.csv');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Zefanya finance report","November 2026"', $csv);
        $this->assertStringContainsString('"Money in",1200.00', $csv);
        $this->assertStringContainsString('Date,Type,Direction,Reference,Party,Method,"Amount (PHP)",Status', $csv);
        $this->assertStringContainsString('ZEF-RETURN-1', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);

        $filtered = $this->actingAs($this->admin, 'admin')->get(route('admin.finance.export', ['month' => '2026-11', 'type' => 'return_refund']));
        $filtered->assertDownload('zefanya-finance-2026-11-return_refund.csv');
        $this->assertStringNotContainsString('GCASH-PAYOUT-9', $filtered->streamedContent());
    }
}
