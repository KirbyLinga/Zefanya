<?php

namespace App\Console\Commands;

use App\Services\Logistics\PickupSessionService;
use Illuminate\Console\Command;
use Throwable;

class RollMissedPickupWindowsCommand extends Command
{
    protected $signature = 'pickups:roll-missed-windows';

    protected $description = 'Move ready parcels whose pickup window passed to the next window (not a failed pickup)';

    public function handle(PickupSessionService $sessions): int
    {
        try {
            $rolled = $sessions->rollMissedWindows();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Rolling missed pickup windows failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Rescheduled: {$rolled}.");

        return self::SUCCESS;
    }
}
