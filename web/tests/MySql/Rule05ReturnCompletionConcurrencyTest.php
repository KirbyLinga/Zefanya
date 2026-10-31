<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Rule05ReturnCompletionConcurrencyTest extends TestCase
{
    private static bool $prepared = false;

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$prepared) {
            Preflight::prepareSchema();
            self::$prepared = true;
        }
        Preflight::clean();
    }

    private function waitForFile(string $file, array $processes): void
    {
        $deadline = microtime(true) + 25;
        while (! is_file($file)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('RULE-05 worker exited before synchronization: '.$process->getOutput().$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                $this->fail('RULE-05 worker synchronization timed out.');
            }
            usleep(10000);
        }
    }

    #[TestWith(['return', 'complete'])]
    #[TestWith(['complete', 'return'])]
    public function test_overlapping_return_and_completion_have_only_one_winner(string $first, string $second): void
    {
        $buyer = Buyer::factory()->create();
        $parent = Order::factory()->create(['buyer_id' => $buyer->id, 'discount_minor' => 0]);
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['order_id' => $parent->id, 'subtotal_minor' => 10000]);
        $parcel->forceFill(['shipping_fee_minor' => 0, 'voucher_discount_minor' => 0])->save();
        $delivery = StatusHistory::query()->create(['seller_order_id' => $parcel->id,
            'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $delivery->forceFill(['created_at' => '2026-10-20 16:00:00'])->saveQuietly();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => '2026-10-20 16:00:00',
            'collected_at' => '2026-10-20 16:00:00', 'collected_amount_minor' => 10000])->save();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $directory = storage_path('logs/rule05-races/'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $processes = [];
        foreach (['a' => $first, 'b' => $second] as $name => $action) {
            $task = ['id' => $parcel->id, 'buyer' => $buyer->id, 'action' => $action,
                'now' => $action === 'return' ? '2026-10-27 15:59:59' : '2026-10-27 16:00:00',
                'directory' => $directory, 'name' => $name];
            file_put_contents($directory.'/'.$name.'.json', json_encode($task));
            $processes[$name] = new Process([PHP_BINARY, __DIR__.'/rule05-worker.php', $directory.'/'.$name.'.json'], base_path());
            $processes[$name]->setTimeout(35);
        }
        try {
            $processes['a']->start();
            $this->waitForFile($directory.'/a.held', [$processes['a']]);
            $processes['b']->start();
            $this->waitForFile($directory.'/b.attempting', $processes);
            $connectionId = (int) file_get_contents($directory.'/b.attempting');
            $blocked = false;
            $deadline = microtime(true) + 10;
            while (! $blocked && microtime(true) < $deadline) {
                foreach (DB::connection()->getPdo()->query('SHOW FULL PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $sql = strtolower((string) ($row['Info'] ?? ''));
                    if ((int) $row['Id'] === $connectionId && str_contains($sql, 'seller_orders') && str_contains($sql, 'for update')) {
                        $blocked = true;
                    }
                }
                usleep(10000);
            }
            $this->assertTrue($blocked, 'The second connection must actually overlap on the shipment lock.');
            touch($directory.'/release');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            }
            $a = json_decode(file_get_contents($directory.'/a.result.json'), true);
            $b = json_decode(file_get_contents($directory.'/b.result.json'), true);
            $this->assertSame('success', $a['result']);
            $this->assertSame($first === 'return' ? 'skipped' : 'rejected', $b['result']);
            $this->assertSame($first === 'return' ? OrderStatus::Delivered : OrderStatus::Completed, $parcel->fresh()->status);
            $this->assertDatabaseCount('parcel_returns', $first === 'return' ? 1 : 0);
            $this->assertSame($first === 'return' ? 0 : 1, StatusHistory::where('to_status', OrderStatus::Completed)->count());
            $this->assertSame($first === 'return' ? null : 500, $parcel->fresh()->commission_minor);
            $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        } finally {
            touch($directory.'/release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }
}
