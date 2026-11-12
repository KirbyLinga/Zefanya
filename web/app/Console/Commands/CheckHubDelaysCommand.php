<?php

namespace App\Console\Commands;

use App\Services\Logistics\HubDelayService;
use Illuminate\Console\Command;

class CheckHubDelaysCommand extends Command
{
    protected $signature = 'parcels:check-hub-delays';

    protected $description = 'Escalate hub delays measured from verified parcel receipts';

    public function handle(HubDelayService $delays): int
    {
        $this->info('Hub delay cases updated: '.$delays->sweep().'.');

        return self::SUCCESS;
    }
}
