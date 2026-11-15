<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CodRemittanceConcurrencyTest extends TestCase
{
    use UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    private function fixture(): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $rider = Rider::factory()->create(['logistics_provider_id' => $provider->id, 'status' => Rider::STATUS_APPROVED]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id, 'rider_id' => $rider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['delivery_courier_id' => $courier->id, 'subtotal_minor' => 8000]);
        $order->forceFill(['shipping_fee_minor' => 500])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 8500]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 8500])->save();
        $remittance = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 8500, 'status' => CodRemittance::PENDING_REMITTANCE]);
        $remittance->snapshotAllocation();
        $remittance->save();
        $admin = Admin::factory()->create(['role' => 'admin']);

        return compact('provider', 'rider', 'order', 'payment', 'remittance', 'admin');
    }

    private function handover(array $f, string $amount = '85.00'): array
    {
        return ['action' => 'cod_handover', 'provider' => $f['provider']->id, 'remittance' => $f['remittance']->id,
            'data' => ['received_amount' => $amount, 'shortage_rider_id' => $f['rider']->id, 'shortage_reason' => 'The rider did not hand over all cash.']];
    }

    private function shortage(array $f): void
    {
        app(CodRemittanceService::class)->confirmReceipt($f['remittance'], $f['provider'], 6000, $f['rider']->id, 'The rider did not hand over all cash.');
    }

    private function recovery(array $f, string $amount, string $reference): array
    {
        return ['action' => 'cod_recovery', 'provider' => $f['provider']->id, 'remittance' => $f['remittance']->id,
            'data' => ['recovered_amount' => $amount, 'reference' => $reference]];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_duplicate_handover_and_stale_full_confirmation_serialize(bool $partial): void
    {
        $f = $this->fixture();
        $results = Race::run($this->handover($f, $partial ? '60.00' : '85.00') + ['hold' => 'parcel'], $this->handover($f) + ['watch' => 'parcel']);
        $this->assertSame(302, $results['a']['status']);
        $this->assertSame($partial ? 409 : 302, $results['b']['status']);
        $row = $f['remittance']->fresh();
        $this->assertSame($partial ? 6000 : 8500, $row->received_amount_minor);
        $this->assertSame($partial ? 2500 : 0, $row->outstandingShortageMinor());
        $this->assertSame($partial ? 0 : 1, SellerSettlement::query()->count());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'remittance_confirmed')->count());
        $this->assertSame(8500, $f['payment']->fresh()->collected_amount_minor);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_recovery_retries_and_distinct_installments_serialize(bool $distinct): void
    {
        $f = $this->fixture();
        $this->shortage($f);
        $results = Race::run($this->recovery($f, '10.00', 'RECOVERY-A') + ['hold' => 'parcel'],
            $this->recovery($f, $distinct ? '15.00' : '10.00', $distinct ? 'RECOVERY-B' : 'RECOVERY-A') + ['watch' => 'parcel']);
        $this->assertSame(302, $results['a']['status']);
        $this->assertSame($distinct ? 302 : 422, $results['b']['status']);
        $row = $f['remittance']->fresh();
        $this->assertSame($distinct ? 8500 : 7000, $row->received_amount_minor);
        $this->assertSame($distinct ? 2500 : 1000, $row->shortage_recovered_minor);
        $this->assertSame(2500, $row->shortage_minor);
        $this->assertSame($distinct ? 0 : 1500, $row->outstandingShortageMinor());
        $this->assertSame($distinct ? 1 : 0, SellerSettlement::query()->count());
        $this->assertSame($distinct ? 2 : 1, OperationsAuditLog::query()->where('action', 'cod_shortage_recovered')->count());
        if (! $distinct) {
            $position = app(PlatformFinanceService::class)->position();
            $this->assertSame(7000, $position['cashAtCompaniesMinor']);
            $this->assertSame(1500, $position['cashWithRidersMinor']);
        }
    }

    public function test_duplicate_platform_submissions_cannot_allocate_the_same_cash_twice(): void
    {
        $f = $this->fixture();
        app(CodRemittanceService::class)->confirmReceipt($f['remittance'], $f['provider'], 8500);
        $task = ['action' => 'cod_submit', 'provider' => $f['provider']->id,
            'data' => ['parcels' => [$f['remittance']->id], 'expected_amount_minor' => 8000, 'reference' => 'SUBMIT-A']];
        $other = $task;
        $other['data']['reference'] = 'SUBMIT-B';
        $results = Race::run($task + ['hold' => 'provider'], $other + ['watch' => 'provider']);
        $this->assertSame(302, $results['a']['status']);
        $this->assertSame(409, $results['b']['status']);
        $this->assertSame(1, LogisticsRemittance::query()->count());
        $this->assertSame(8000, LogisticsRemittance::query()->sole()->amount_minor);
        $this->assertNull($f['remittance']->fresh()->zefanya_received_at);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'logistics_remittance_submitted')->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_platform_confirmation_serializes_duplicate_and_rejection(bool $reject): void
    {
        $f = $this->fixture();
        app(CodRemittanceService::class)->confirmReceipt($f['remittance'], $f['provider'], 8500);
        $batch = app(LogisticsRemittanceService::class)->submit($f['provider'], [$f['remittance']->id], 8000, 'PLATFORM-CASH');
        $task = ['action' => 'cod_platform_confirm', 'admin' => $f['admin']->id, 'batch' => $batch->id,
            'data' => ['received_amount' => '80.00', 'parcels' => [$f['remittance']->id]]];
        $other = $reject ? ['action' => 'cod_platform_reject', 'admin' => $f['admin']->id, 'batch' => $batch->id,
            'data' => ['reason' => 'Transfer did not arrive as stated.']] : $task;
        $results = Race::run($task + ['hold' => 'logistics_remittance'], $other + ['watch' => 'logistics_remittance']);
        $this->assertSame(302, $results['a']['status']);
        $this->assertSame($reject ? 409 : 302, $results['b']['status']);
        $this->assertSame(LogisticsRemittance::CONFIRMED, $batch->fresh()->status);
        $this->assertSame(8000, $batch->fresh()->received_amount_minor);
        $this->assertNotNull($f['remittance']->fresh()->zefanya_received_at);
        $this->assertSame($batch->id, $f['remittance']->fresh()->logistics_remittance_id);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'logistics_remittance_confirmed')->count());
        $this->assertSame(0, OperationsAuditLog::query()->where('action', 'logistics_remittance_rejected')->count());
    }

    public function test_recovery_cannot_be_overwritten_by_a_stale_handover(): void
    {
        $f = $this->fixture();
        $this->shortage($f);
        $results = Race::run($this->recovery($f, '10.00', 'RECOVERY-FIRST') + ['hold' => 'parcel'], $this->handover($f) + ['watch' => 'parcel']);
        $this->assertSame(302, $results['a']['status']);
        $this->assertSame(409, $results['b']['status']);
        $this->assertSame(7000, $f['remittance']->fresh()->received_amount_minor);
        $this->assertSame(1500, $f['remittance']->fresh()->outstandingShortageMinor());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'cod_shortage_recovered')->count());
        $this->assertSame(0, SellerSettlement::query()->count());
    }

    #[TestWith(['handover'])]
    #[TestWith(['recovery'])]
    #[TestWith(['platform'])]
    public function test_audit_failure_rolls_back_mysql_cash_history_and_settlement(string $action): void
    {
        $f = $this->fixture();
        $cash = app(CodRemittanceService::class);
        $platform = app(LogisticsRemittanceService::class);
        $batch = null;
        if ($action === 'recovery') {
            $this->shortage($f);
        } elseif ($action === 'platform') {
            $cash->confirmReceipt($f['remittance'], $f['provider'], 8500);
            $batch = $platform->submit($f['provider'], [$f['remittance']->id], 8000, 'MYSQL-ROLLBACK');
        }
        $before = $f['remittance']->fresh()->getRawOriginal();
        $batchBefore = $batch?->fresh()->getRawOriginal();
        $logs = OperationsAuditLog::query()->count();
        $settlements = SellerSettlement::query()->count();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'insert into `operations_audit_logs`')) {
                $armed = false;
                throw new \RuntimeException('Controlled MySQL COD audit failure.');
            }
        });
        try {
            match ($action) {
                'handover' => $cash->confirmReceipt($f['remittance'], $f['provider'], 8500),
                'recovery' => $cash->recordShortageRecovery($f['remittance'], $f['provider'], 2500, 'MYSQL-RECOVERY-FAIL'),
                'platform' => $platform->confirm($batch, $f['admin'], 8000),
            };
            $this->fail('Controlled failure did not interrupt MySQL transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled MySQL COD audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $f['remittance']->fresh()->getRawOriginal());
        $this->assertSame($batchBefore, $batch?->fresh()->getRawOriginal());
        $this->assertSame($logs, OperationsAuditLog::query()->count());
        $this->assertSame($settlements, SellerSettlement::query()->count());
    }
}
