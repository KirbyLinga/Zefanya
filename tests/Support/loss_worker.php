<?php

/*
| Child process for ParcelLossConcurrencyTest. It boots the app against the
| same MySQL database, waits for the barrier file so every worker starts at
| the same instant, runs one lost-parcel operation, and writes a JSON result.
| Never run this by hand: the test supplies the task file.
*/

use App\Models\Admin\Admin;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Shared\Incidents;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$task = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($task['ready'], '1');

$deadline = microtime(true) + 20;
while (! is_file($task['barrier'])) {
    if (microtime(true) > $deadline) {
        file_put_contents($task['result'], json_encode(['ok' => false, 'class' => 'BarrierTimeout', 'message' => 'The barrier never opened.']));
        exit(1);
    }
    usleep(1000);
}

try {
    $admin = Admin::query()->findOrFail($task['admin']);
    $value = match ($task['op']) {
        'confirm' => app(ParcelIncidentService::class)->resolve(Incidents::query()->findOrFail($task['incident']), $admin, [
            'event' => 'CLOSE_LOST', 'reason' => 'Confirmed lost',
        ]),
        'confirm_many' => app(ParcelLossService::class)->confirmMany($task['incidents'], $admin, 'Confirmed lost'),
        'pay' => app(ParcelLossService::class)->recordCompensationPayment(
            ParcelLossCase::query()->findOrFail($task['case']), $admin, $task['reference'], $task['paid_on'],
        )->id,
        default => throw new InvalidArgumentException('Unknown operation.'),
    };
    file_put_contents($task['result'], json_encode(['ok' => true, 'value' => is_array($value) ? $value : null]));
} catch (Throwable $e) {
    file_put_contents($task['result'], json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()]));
}
