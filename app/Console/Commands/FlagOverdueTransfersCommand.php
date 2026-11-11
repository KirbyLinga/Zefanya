<?php

namespace App\Console\Commands;

use App\Services\Logistics\StuckTransferService;
use Illuminate\Console\Command;
use Throwable;

class FlagOverdueTransfersCommand extends Command
{
    protected $signature = 'transfers:flag-overdue';

    protected $description = 'Tell sending companies once about overdue transfers, and Admin once if still unresolved (no custody change)';

    public function handle(StuckTransferService $transfers): int
    {
        try {
            $result = $transfers->notifyOverdue();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Flagging overdue transfers failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Notified: {$result['notified']}. Escalated to Admin: {$result['escalated']}.");

        return self::SUCCESS;
    }
}
