<?php

namespace App\Console\Commands;

use App\Services\Buyer\AutomaticBuyerReceiptService;
use App\Support\RuntimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class AutoCompleteBuyerReceiptsCommand extends Command
{
    protected $signature = 'orders:auto-complete-receipts {--batch=100 : Records per batch (1-1000)}';

    protected $description = 'Complete undisputed delivered Seller orders after seven days without Buyer confirmation';

    public function handle(AutomaticBuyerReceiptService $receipts): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);

        if ($batchSize === false) {
            $this->error('Batch size must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        // StatusHistory otherwise tolerates legacy schemas by omitting attribution.
        // Automatic completion must always record its system source.
        foreach (['actor_type', 'actor_id', 'source'] as $column) {
            if (! RuntimeSchema::hasColumn('status_histories', $column)) {
                $this->error('Status-history attribution migrations are required before automatic completion.');

                return self::FAILURE;
            }
        }

        $cutoff = CarbonImmutable::now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS);
        $completed = 0;
        $skipped = 0;
        $failed = 0;

        AutomaticBuyerReceiptService::eligibleOrders($cutoff)
            ->select('seller_orders.id')
            ->chunkById($batchSize, function (Collection $orders) use ($receipts, $cutoff, &$completed, &$skipped, &$failed): void {
                foreach ($orders as $order) {
                    try {
                        if ($receipts->complete((int) $order->getKey(), $cutoff)) {
                            $completed++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                        $this->warn("Seller order #{$order->getKey()} failed: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Completed: {$completed}; skipped after recheck: {$skipped}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
