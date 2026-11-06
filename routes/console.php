<?php

use App\Console\Commands\AutoApproveOverdueReturnsCommand;
use App\Console\Commands\AutoCompleteBuyerReceiptsCommand;
use App\Console\Commands\RollMissedPickupWindowsCommand;
use App\Support\ScheduledTaskAlert;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$alert = app(ScheduledTaskAlert::class);

$alert->apply(
    Schedule::command(AutoCompleteBuyerReceiptsCommand::class)->everyFiveMinutes()->withoutOverlapping(),
    'orders:auto-complete-receipts',
);
$alert->apply(
    Schedule::command(AutoApproveOverdueReturnsCommand::class)->everyFiveMinutes()->withoutOverlapping(),
    'returns:auto-approve-overdue',
);
$alert->apply(
    Schedule::command(RollMissedPickupWindowsCommand::class)->everyFiveMinutes()->withoutOverlapping(),
    'pickups:roll-missed-windows',
);
$alert->apply(
    Schedule::command('sanctum:prune-expired --hours=24')->daily(),
    'sanctum:prune-expired',
);
$alert->apply(
    Schedule::command('queue:prune-failed --hours=168')->daily(),
    'queue:prune-failed',
);
