<?php

namespace App\Console\Commands;

use App\Services\Seller\PreparationDeadlineService;
use Illuminate\Console\Command;
use Throwable;

class EscalateOverduePreparationCommand extends Command
{
    protected $signature = 'orders:escalate-preparation-overdue';

    protected $description = 'Notify each Seller once when an accepted order passes its preparation deadline (no cancellation or stock/money change)';

    public function handle(PreparationDeadlineService $deadlines): int
    {
        try {
            $result = $deadlines->escalateOverdue();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Preparing overdue escalations failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Notified: {$result['notified']}.");

        return self::SUCCESS;
    }
}
