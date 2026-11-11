<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\OriginSharePayout;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransferCourierAssignment;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\OriginSharePaymentRecorded;
use App\Notifications\Rider\RiderPayoutRecorded;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\LogisticsFinanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Rider\RiderEarningService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerTransactionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** Extend the canonical seeded route test through every required payment. */
class CompleteFinancialScenariosTest extends DeliveryScenarioSeederTest
{
    private array $requestEvidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00:00', 'Asia/Manila'));
        // The live rule set: every new batch and handoff needs a Courier before dispatch.
        config(['transfers.require_transport' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $route = $this->app['router']->getCurrentRoute();
        $this->requestEvidence[] = [
            'method' => $method, 'url' => parse_url($uri, PHP_URL_PATH),
            'role' => auth()->getDefaultDriver(), 'route' => $route?->getName(),
            'controller' => $route?->getActionName(), 'http_status' => $response->getStatusCode(),
            'parcel_states' => DB::table('seller_orders')->get(['id', 'status', 'custody_provider_id', 'custody_hub_id'])->toArray(),
        ];

        return $response;
    }

    #[DataProvider('supportedScenarios')]
    public function test_seeded_network_carries_the_parcel_from_checkout_to_completion(
        string $sellerSlug, string $buyerSlug, string $originSlug, string $pickupSlug, string $deliverySlug,
        array $modes, array $hubs, int $expectedHandoffs,
    ): void {
        $this->requestEvidence = [];
        $evidence = ['scenario' => $sellerSlug, 'mode' => 'HTTP feature simulation; not browser clicks', 'passed' => false];
        try {
            parent::test_seeded_network_carries_the_parcel_from_checkout_to_completion(
                $sellerSlug, $buyerSlug, $originSlug, $pickupSlug, $deliverySlug, $modes, $hubs, $expectedHandoffs,
            );
            $parcel = SellerOrder::query()->sole();
            $this->assertSame(OrderStatus::Completed, $parcel->status);
            $this->assertSame(50000, $parcel->subtotal_minor);
            $this->assertSame(5000, $parcel->commission_minor);
            $this->assertSame(45000, $parcel->seller_net_minor);
            $this->assertSame(0, (int) $parcel->seller_funded_discount_minor);
            $this->assertSame(0, (int) $parcel->marketplace_funded_discount_minor);
            $cod = CodRemittance::query()->where('seller_order_id', $parcel->id)->sole();
            $destination = $cod->provider;
            $origin = $parcel->carrierProvider;
            $seller = $parcel->seller;
            $admin = Admin::query()->firstOrFail();
            $shipping = $parcel->shipping_fee_minor;
            // One fixed fee for every route: ₱50 for the first 2 kg.
            $this->assertSame(5000, $shipping);
            $this->assertSame(50000 + $shipping, $cod->amount_minor);
            $originShare = $expectedHandoffs ? 2000 : 0;
            $retained = $shipping - $originShare;
            $due = $cod->amount_minor - $retained;
            $this->assertSame($originShare, $cod->originShareMinor());
            $this->assertSame($retained, $cod->keptShippingMinor());
            $this->assertSame(CodRemittance::PENDING_REMITTANCE, $cod->status);
            $snapshot = [$cod->kept_shipping_minor, $cod->origin_share_minor];

            // Completed alone cannot release the seller's money before actual cash reconciliation.
            $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
            $this->actingAs($destination, 'logistics')->get(route('logistics.cod-remittances.index'))->assertOk()->assertSee('Confirm cash received');
            $receipt = ['received_amount' => $this->pesos($cod->amount_minor)];
            $this->post(route('logistics.cod-remittances.confirm', $cod), $receipt)->assertRedirect()->assertSessionHasNoErrors();
            $this->post(route('logistics.cod-remittances.confirm', $cod), $receipt)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $cod->fresh()->status);
            $this->assertSame($cod->amount_minor, $cod->fresh()->received_amount_minor);
            $this->assertSame(1, SellerSettlement::query()->where('seller_order_id', $parcel->id)->count());
            $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));

            $this->get(route('logistics.earnings.index'))->assertOk()->assertSee('I sent this payment');
            $this->post(route('logistics.earnings.remit'), [
                'parcels' => [$cod->id], 'expected_amount_minor' => $due, 'reference' => 'RETEST-CASH-'.$sellerSlug,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $remittance = LogisticsRemittance::query()->sole();
            $this->assertSame($due, $remittance->amount_minor);
            $this->assertSame($retained, $remittance->shipping_total_minor);
            $this->assertSame(LogisticsRemittance::SUBMITTED, $remittance->status);
            $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))->assertOk();
            $this->post(route('admin.logistics-remittances.confirm', $remittance), ['received_amount' => $this->pesos($due)])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->post(route('admin.logistics-remittances.confirm', $remittance), ['received_amount' => $this->pesos($due)])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNotNull($cod->fresh()->zefanya_received_at);
            $this->assertSame($due, $remittance->fresh()->received_amount_minor);
            $this->assertSame(45000, app(SellerPayoutRequestService::class)->availableBalance($seller));

            if ($expectedHandoffs) {
                $this->post(route('admin.logistics-remittances.pay-origin', $origin), [
                    'through_id' => $cod->id, 'expected_amount_minor' => $originShare,
                    'reference' => 'RETEST-ORIGIN-'.$sellerSlug, 'paid_on' => now()->toDateString(),
                ])->assertRedirect()->assertSessionHasNoErrors();
                $payout = OriginSharePayout::query()->sole();
                $this->assertSame($originShare, $payout->amount_minor);
                $this->assertSame($payout->id, $cod->fresh()->origin_share_payout_id);
                Notification::assertSentTo($origin, OriginSharePaymentRecorded::class);
            } else {
                $this->assertSame(0, OriginSharePayout::query()->count());
            }

            // One batch per rider, including both jobs when the local rider did both.
            // Couriers who carried the transfers earn nothing through Zefanya.
            $earnings = RiderEarning::query()->where('seller_order_id', $parcel->id)->get();
            $this->assertSame(2, $earnings->count());
            $this->assertSame(2, RiderEarning::query()->count());
            $this->assertSame(3000, $earnings->firstWhere('job', RiderEarning::PICKUP)->amount_minor);
            $this->assertSame(5000, $earnings->firstWhere('job', RiderEarning::DELIVERY)->amount_minor);
            $this->assertSame(Manifest::query()->count() + ParcelHandoff::query()->count(),
                TransferCourierAssignment::query()->count(), 'Every transfer recorded its Courier.');
            foreach ($earnings->groupBy('courier_id') as $jobs) {
                $courier = $jobs->first()->courier;
                $amount = (int) $jobs->sum('amount_minor');
                $this->actingAs($courier->provider, 'logistics')->post(route('logistics.earnings.pay-rider', $courier), [
                    'through_id' => $jobs->max('id'), 'expected_amount_minor' => $amount,
                    'reference' => 'RETEST-RIDER-'.$sellerSlug.'-'.$courier->id, 'paid_on' => now()->toDateString(),
                ])->assertRedirect()->assertSessionHasNoErrors();
                $summary = app(RiderEarningService::class)->summaryFor($courier);
                $this->assertSame(0, $summary['pendingMinor']);
                $this->assertSame($amount, $summary['paidMinor']);
                Notification::assertSentTo($courier->rider, RiderPayoutRecorded::class);
            }
            $this->assertSame(8000, (int) RiderPayout::query()->sum('amount_minor'));

            $this->actingAs($seller, 'seller')->get(route('seller.payout-requests.index'))->assertOk()->assertSee('Request payout');
            $this->post(route('seller.payout-requests.store'), [
                'amount' => '450.00', 'idempotency_key' => (string) Str::uuid(), 'destination' => 'Isolated retest payment destination',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $sellerPayout = SellerPayoutRequest::query()->sole();
            $this->assertSame(0, $sellerPayout->debt_deducted_minor);
            $this->actingAs($admin, 'admin')->get(route('admin.seller-payouts.index'))->assertOk();
            $this->post(route('admin.seller-payouts.pay', $sellerPayout), ['reference' => 'RETEST-SELLER-'.$sellerSlug])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(SellerPayoutRequest::PAID, $sellerPayout->fresh()->status);
            $this->assertSame('RETEST-SELLER-'.$sellerSlug, $sellerPayout->fresh()->payout_reference);
            $settlement = SellerSettlement::query()->sole();
            $this->assertSame(SellerSettlement::PAID, $settlement->status);
            $this->assertSame(45000, $settlement->paid_amount_minor);
            $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
            $this->assertSame($snapshot, [$cod->fresh()->kept_shipping_minor, $cod->fresh()->origin_share_minor]);
            $this->assertSame(5000, $due - $originShare - $sellerPayout->amount_minor);
            $platform = app(PlatformFinanceService::class);
            $this->assertSame(5000, $platform->earnings(false)['netMinor']);
            $position = $platform->position();
            $this->assertSame(0, $position['totalLiabilitiesMinor']);
            $this->assertSame(0, $position['outstandingCashMinor']);
            $this->assertSame(0, $position['sellerDebtMinor']);
            $statements = [];
            foreach (collect([$origin, $destination])->unique('id') as $company) {
                $statement = app(LogisticsFinanceService::class)->statement($company);
                $this->assertSame(0, $statement['riderUnpaidMinor']);
                $this->assertSame($statement['grossMinor'] - $statement['riderExpenseMinor'], $statement['netMinor']);
                // Trip pay is a rider expense too, and every rider was paid in full.
                $this->assertSame((int) RiderEarning::query()->where('logistics_provider_id', $company->id)->sum('amount_minor'), $statement['riderExpenseMinor']);
                $this->assertSame($statement['riderExpenseMinor'], $statement['riderPaidMinor']);
                $statements[$company->business_name] = $statement;

                // Shipping fees table: + cash from the rider, − Zefanya's part once sent; the company keeps its fee.
                $balances = app(LogisticsRemittanceService::class)->parcelBalances($company);
                if ((int) $company->id === (int) $destination->id) {
                    $this->assertSame([$cod->id => ['cashIn' => true, 'sentOut' => true, 'balanceMinor' => $retained]], $balances);
                    $this->actingAs($company, 'logistics')->get(route('logistics.earnings.index'))->assertOk()
                        ->assertSee('Balance')->assertSee('+₱'.number_format($cod->amount_minor / 100, 2))->assertSee('−₱'.number_format($due / 100, 2));
                } else {
                    $this->assertSame([], $balances, 'The origin company collected no parcel cash.');
                }
            }

            // Seller: + the order's net, − the paid withdrawal, ending at zero.
            $sellerLedger = app(SellerTransactionService::class)->for($seller);
            $this->assertSame(['Withdrawal', 'Order earnings'], $sellerLedger->pluck('type')->all());
            $this->assertSame([-45000, 45000], $sellerLedger->pluck('amountMinor')->all());
            $this->assertSame(0, $sellerLedger->first()['balanceAfterMinor']);
            $this->actingAs($seller, 'seller')->get(route('seller.earnings.index'))->assertOk()
                ->assertSee('Transactions')->assertSee('Order earnings')->assertSee('Withdrawal');
            $evidence += [
                'subtotal_minor' => 50000, 'voucher_minor' => 0, 'buyer_cod_minor' => $cod->amount_minor,
                'shipping_minor' => $shipping, 'destination_retained_minor' => $retained, 'origin_paid_minor' => $originShare,
                'zefanya_received_minor' => $due, 'commission_minor' => 5000, 'seller_paid_minor' => 45000,
                'rider_paid_minor' => 8000, 'platform_retained_minor' => 5000,
                'logistics_statements' => $statements, 'final_position' => $position,
                'references' => ['cash' => $remittance->reference, 'seller' => $sellerPayout->fresh()->payout_reference],
                'audit_actions' => OperationsAuditLog::query()->pluck('action')->all(),
                'saved_modes' => $modes, 'saved_hubs' => $hubs,
            ];
            $evidence['passed'] = true;
        } finally {
            $directory = getenv('LOGISTICS_RETEST_EVIDENCE');
            if (is_string($directory) && is_dir($directory)) {
                $evidence['requests'] = $this->requestEvidence;
                file_put_contents($directory.'/'.$sellerSlug.'.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        }
    }

    private function pesos(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
