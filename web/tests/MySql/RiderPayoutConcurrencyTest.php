<?php

namespace Tests\MySql;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderEarningService;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RiderPayoutConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function courier(): Courier
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);

        return Courier::factory()->create(['logistics_provider_id' => $provider->id]);
    }

    private function earning(Courier $courier, int $amount): RiderEarning
    {
        return RiderEarning::query()->forceCreate([
            'seller_order_id' => SellerOrder::factory()->create()->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $courier->logistics_provider_id,
            'job' => RiderEarning::DELIVERY, 'amount_minor' => $amount, 'earned_at' => now(),
        ]);
    }

    private function payment(Courier $courier, RiderEarning $last, int $amount, string $reference = 'MYSQL-RIDER'): array
    {
        $batch = app(RiderEarningService::class)->unpaidByCourier($courier->provider)->firstWhere('courierId', $courier->id);

        return ['action' => 'rider_pay', 'provider' => $courier->logistics_provider_id, 'courier' => $courier->id, 'data' => [
            'through_id' => $last->id, 'expected_amount_minor' => $amount, 'payout_revision' => $batch['payoutRevision'],
            'reference' => $reference, 'paid_on' => now(StoreSale::TIMEZONE)->toDateString(),
        ]];
    }

    private function assertOverlap(array $results): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }

    #[TestWith([200000])]
    #[TestWith([100000000])]
    public function test_concurrent_confirmations_of_the_same_batch_commit_one_payment_and_audit(int $amount): void
    {
        $courier = $this->courier();
        $earning = $this->earning($courier, $amount);
        $payment = $this->payment($courier, $earning, $amount);
        $results = Race::run($payment + ['hold' => 'courier'], $payment + ['watch' => 'courier']);

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['rider'], $results['b']['validation_fields']);
        $payout = RiderPayout::query()->sole();
        $this->assertSame($amount, $payout->amount_minor);
        $this->assertEquals($payout->id, $earning->fresh()->rider_payout_id);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'rider_payout_paid')->count());
        $this->assertOverlap($results);
    }

    #[TestWith([1])]
    #[TestWith([1000])]
    public function test_competing_payments_cannot_apply_four_thousand_to_three_thousand_in_earnings(int $scale): void
    {
        $courier = $this->courier();
        $this->earning($courier, 100000 * $scale);
        $second = $this->earning($courier, 100000 * $scale);
        $third = $this->earning($courier, 100000 * $scale);
        $results = Race::run(
            $this->payment($courier, $second, 200000 * $scale) + ['hold' => 'courier'],
            $this->payment($courier, $third, 200000 * $scale, 'MYSQL-OVERLAP') + ['watch' => 'courier'],
        );

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['rider'], $results['b']['validation_fields']);
        $this->assertSame(200000 * $scale, RiderPayout::query()->sole()->amount_minor);
        $this->assertNull($third->fresh()->rider_payout_id);
        $this->assertSame(100000 * $scale, app(RiderEarningService::class)->summaryFor($courier)['pendingMinor']);
        $this->assertOverlap($results);
    }

    public function test_a_retry_waiting_behind_reversal_cannot_reapply_the_old_transfer(): void
    {
        $courier = $this->courier();
        $earning = $this->earning($courier, 100000);
        $payment = $this->payment($courier, $earning, 100000);
        $data = $payment['data'];
        $payout = app(RiderEarningService::class)->payCourier(
            $courier->provider, $courier, $earning->id, 100000, $data['reference'], $data['paid_on'], $data['payout_revision'],
        );
        $reversal = ['action' => 'rider_reverse', 'provider' => $courier->logistics_provider_id, 'payout' => $payout->id, 'data' => [
            'reason' => 'The payment record needs to be corrected.', 'confirm_reversal' => '1',
        ]];
        $results = Race::run($reversal + ['hold' => 'courier'], $payment + ['watch' => 'courier']);

        $this->assertSame([302, 422], array_column($results, 'status'));
        $this->assertSame(['rider'], $results['b']['validation_fields']);
        $this->assertDatabaseCount('rider_payouts', 1);
        $this->assertNull($earning->fresh()->rider_payout_id);
        $this->assertNotNull($payout->fresh()->reversal);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'rider_payout_paid')->count());
        $this->assertOverlap($results);
    }
}
