<?php

namespace App\Console\Commands;

use App\Services\Logistics\StuckTransferService;
use Illuminate\Console\Command;

class FlagOverdueTransfersCommand extends Command
{
    protected $signature = 'transfers:flag-overdue';

    protected $description = 'Notify the sending company and escalate overdue transfers to Admin';

    public function handle(StuckTransferService $transfers): int
    {
        if (! $transfers->notificationStorageAvailable()) {
            $this->warn('Transfer notification audit columns are not available.');

            return self::FAILURE;
        }

        $transfers->sendOverdueNotices();

        return self::SUCCESS;
    }
}
