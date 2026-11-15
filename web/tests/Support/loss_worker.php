<?php

/*
| Child process for ParcelLossConcurrencyTest. It boots the app against the
| same isolated MySQL database, holds the first transaction until a competing
| locking read is observed, then records one lost-parcel operation's result.
| Never run this by hand: the test supplies the task file.
*/

use App\Models\Admin\Admin;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Shared\Incidents;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Preflight;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = Preflight::application();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Loss worker preflight failed: '.get_class($exception).' (credentials suppressed).');
    exit(2);
}

$task = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($task['directory'].'/'.$task['index'].'.connection', (string) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
file_put_contents($task['ready'], '1');

$deadline = microtime(true) + 20;
while (! is_file($task['index'] === 0 ? $task['directory'].'/0.go' : $task['barrier'])) {
    if (microtime(true) > $deadline) {
        file_put_contents($task['result'], json_encode(['ok' => false, 'class' => 'BarrierTimeout', 'message' => 'The barrier never opened.']));
        exit(1);
    }
    usleep(1000);
}

try {
    if ($task['index'] === 0) {
        $held = false;
        DB::listen(static function (QueryExecuted $event) use ($task, &$held): void {
            if (! $held && str_contains(strtolower($event->sql), 'for update') && DB::transactionLevel() > 0) {
                $held = true;
                file_put_contents($task['directory'].'/0.held', json_encode([
                    'connection' => (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
                    'transaction_level' => DB::transactionLevel(), 'time' => microtime(true),
                ]));
                $deadline = microtime(true) + 18;
                while (! is_file($task['directory'].'/0.release')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Loss race lock release deadline.');
                    }
                    usleep(10000);
                }
            }
        });
    }
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
