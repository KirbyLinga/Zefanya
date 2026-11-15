<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Admin\FinanceReportService;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Logistics\ReturnChargeService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Shared\CommissionFinalizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialLedgerIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Exact collection snapshots feed the actual remittance and settlement services. */
    private function settledCash(): array
    {
        $seller = Seller::factory()->create();
        $courier = Courier::factory()->create();
        $provider = $courier->provider;
        $admin = Admin::factory()->create(['role' => 'admin']);
        $parent = Order::factory()->create(['discount_minor' => 10000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 100000,
        ]);
        $order->forceFill(['shipping_fee_minor' => 5000, 'carrier_provider_id' => $provider->id,
            'voucher_discount_minor' => 10000, 'voucher_funding_source' => Voucher::FUNDING_SELLER])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 95000]);
        $payment->recordCodCollection(95000, 'rider', $courier->id);
        $remittance = new CodRemittance;
        $remittance->forceFill(['payment_id' => $payment->id, 'seller_order_id' => $order->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id,
            'amount_minor' => 95000, 'status' => CodRemittance::PENDING_REMITTANCE]);
        $remittance->snapshotAllocation();
        $remittance->save();
        app(CodRemittanceService::class)->confirmReceipt($remittance, $provider, 95000);
        app(CommissionFinalizationService::class)->finalize($order);
        $logistics = app(LogisticsRemittanceService::class);
        $batch = $logistics->submit($provider, [$remittance->id], 90000, 'AUDIT16-COD');
        $logistics->confirm($batch, $admin, 90000);
        $settlement = SellerSettlement::query()->where('seller_order_id', $order->id)->sole();

        return compact('seller', 'provider', 'admin', 'order', 'payment', 'remittance', 'settlement');
    }

    public function test_unpaid_null_settlement_is_included_in_dashboard_liabilities_without_mutation(): void
    {
        $f = $this->settledCash();
        $this->assertNull($f['settlement']->paid_amount_minor);
        $this->assertSame(81000, $f['settlement']->remainingPayoutMinor());
        $this->assertSame(81000, app(SellerPayoutRequestService::class)->availableBalance($f['seller']));
        $before = $f['settlement']->getRawOriginal();
        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame(81000, $position['sellerPayableMinor']);
        $this->assertSame(81000, $position['totalLiabilitiesMinor']);
        $this->actingAs($f['admin'], 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertViewHas('position', fn ($data) => $data['sellerPayableMinor'] === 81000);
        $this->assertSame($before, $f['settlement']->fresh()->getRawOriginal());
    }

    public function test_partial_and_final_payouts_reconcile_cash_commission_dashboard_and_csv(): void
    {
        $f = $this->settledCash();
        $payouts = app(SellerPayoutRequestService::class);
        $request = $payouts->request($f['seller'], '100', (string) Str::uuid(), 'Recorded test transfer');
        $payouts->pay($request, $f['admin'], 'AUDIT16-PARTIAL');
        $this->assertSame(71000, app(PlatformFinanceService::class)->position()['sellerPayableMinor']);
        $request = $payouts->request($f['seller'], '710', (string) Str::uuid(), 'Recorded test transfer');
        $payouts->pay($request, $f['admin'], 'AUDIT16-FINAL');
        $this->assertSame(0, app(PlatformFinanceService::class)->position()['sellerPayableMinor']);
        $finance = app(FinanceReportService::class);
        $period = FinanceReportService::period('2026-11');
        $totals = $finance->totals($finance->ledger($period));
        $summary = $finance->summary($period);
        $this->assertSame(90000, $totals['in']);
        $this->assertSame(81000, $totals['out']);
        $this->assertSame(9000, $summary['commissionMinor']);
        $this->assertSame(9000, $summary['netMinor']);
        $this->assertSame($summary['commissionMinor'], $totals['in'] - $totals['out']);
        $this->assertSame(5000 + 81000 + 9000, $f['payment']->fresh()->collected_amount_minor);
        $csv = $this->actingAs($f['admin'], 'admin')->get(route('admin.finance.export', ['month' => '2026-11']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('"Money in",900.00', $csv);
        $this->assertStringContainsString('"Money out",810.00', $csv);
        $this->assertStringContainsString('Commission,90.00', $csv);
        $this->assertSame(81000, $f['settlement']->fresh()->paid_amount_minor);
    }

    public function test_default_month_earnings_matches_the_explicit_month_at_both_manila_boundaries(): void
    {
        foreach ([['2026-10-31 15:59:59', 500], ['2026-10-31 16:00:00', 1000],
            ['2026-11-30 15:59:59', 2000], ['2026-11-30 16:00:00', 4000]] as [$at, $commission]) {
            $order = SellerOrder::factory()->status(OrderStatus::Completed)->create();
            $order->forceFill(['commission_minor' => $commission, 'commission_finalized_at' => $at])->save();
        }
        $platform = app(PlatformFinanceService::class);
        $this->assertSame(3000, $platform->earnings(true, Carbon::parse('2026-10-31 16:00:00', 'UTC'))['commissionMinor']);
        $this->assertSame(3000, $platform->earnings()['commissionMinor']);
        $this->assertSame(7500, $platform->earnings(false)['commissionMinor']);
    }

    public function test_recorded_refunds_current_month_excludes_next_month_without_erasing_records(): void
    {
        foreach ([['2026-10-31 15:59:59', 500], ['2026-10-31 16:00:00', 1000],
            ['2026-11-30 15:59:59', 2000], ['2026-11-30 16:00:00', 4000]] as [$at, $amount]) {
            ParcelReturn::query()->forceCreate(['seller_order_id' => SellerOrder::factory()->create()->id,
                'status' => 'RETURN_REQUESTED', 'reason' => 'Boundary fixture', 'requested_at' => now(),
                'refunded_at' => $at, 'refund_amount_minor' => $amount]);
        }
        $this->assertSame(3000, app(PlatformFinanceService::class)->position()['refundsRecordedMonthMinor']);
        $this->assertSame(4, ParcelReturn::query()->count());
    }

    public function test_settled_return_charges_appear_once_in_finance_and_csv_in_their_actual_directions(): void
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $provider = LogisticsProvider::factory()->create();
        $charges = [];
        // Existing payable/receivable rows; the actual Admin settlement service records the handovers.
        foreach ([['transit_damage_refund', 'to_zefanya', 12500], ['return_trip', 'to_provider', 5000],
            ['failed_delivery', 'to_provider', 9900]] as [$reason, $direction, $amount]) {
            $order = SellerOrder::factory()->status(OrderStatus::Returned)->create();
            $return = ParcelReturn::query()->forceCreate(['seller_order_id' => $order->id,
                'status' => 'RETURNED_TO_SELLER', 'reason' => 'Recorded return charge fixture', 'requested_at' => now()]);
            $charge = LogisticsReturnCharge::query()->forceCreate([
                'parcel_return_id' => $return->id, 'seller_order_id' => $order->id, 'logistics_provider_id' => $provider->id,
                'reason' => $reason, 'direction' => $direction, 'amount_minor' => $amount]);
            if ($reason !== 'failed_delivery') {
                app(ReturnChargeService::class)->settle($charge, $admin, 'AUDIT16-'.$reason);
            }
            $charges[] = $charge->fresh();
        }
        $before = collect($charges)->map(fn ($charge) => $charge->getRawOriginal())->all();
        $finance = app(FinanceReportService::class);
        $period = FinanceReportService::period('2026-11');
        $ledger = $finance->ledger($period);
        $this->assertCount(2, $ledger->where('type', 'return_charge'));
        $this->assertSame(12500, $finance->totals($ledger)['in']);
        $this->assertSame(5000, $finance->totals($ledger)['out']);
        $this->assertCount(0, $finance->ledger(FinanceReportService::period('2026-10'))->where('type', 'return_charge'));
        $this->actingAs($admin, 'admin')->get(route('admin.finance.index', ['type' => 'return_charge']))->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->total() === 2);
        $csv = $this->get(route('admin.finance.export', ['month' => '2026-11', 'type' => 'return_charge']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('"Money in",125.00', $csv);
        $this->assertStringContainsString('"Money out",50.00', $csv);
        $this->assertStringContainsString('AUDIT16-transit_damage_refund', $csv);
        $this->assertStringContainsString('AUDIT16-return_trip', $csv);
        $this->assertStringNotContainsString('99.00', $csv);
        $this->assertSame($before, collect($charges)->map(fn ($charge) => $charge->fresh()->getRawOriginal())->all());
    }
}
