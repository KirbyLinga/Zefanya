<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use App\Models\Admin\Admin;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationDecision;
use App\Services\Admin\SellerRegistrationDecisionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\MySql\Preflight;

$task = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$directory = $task['directory'];
$name = $task['name'];
$wait = static function (string $file): void {
    $deadline = microtime(true) + 20;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Seller registration worker synchronization deadline.');
        }
        usleep(10000);
    }
};
try {
    Preflight::application();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seller registration MySQL preflight failed: '.get_class($exception).' (credentials suppressed).');
    exit(2);
}
try {
    Notification::fake([SellerRegistrationDecision::class]);
    $seller = Seller::findOrFail($task['seller_id']);
    $admin = Admin::findOrFail($task['admin_id']);
    $loadedStatus = $seller->status;
    $connection = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    file_put_contents($directory.'/'.$name.'.connection', (string) $connection);
    touch($directory.'/'.$name.'.ready');
    $wait($directory.'/'.$name.'.go');
    if ($name === 'a') {
        DB::listen(static function (QueryExecuted $event) use ($directory, $wait): void {
            if (str_contains(strtolower($event->sql), 'sellers') && str_contains(strtolower($event->sql), 'for update')) {
                touch($directory.'/a.held');
                $wait($directory.'/a.release');
            }
        });
    } else {
        DB::connection()->beforeExecuting(static function (string $sql) use ($directory): void {
            if (str_contains(strtolower($sql), 'sellers') && str_contains(strtolower($sql), 'for update')) {
                touch($directory.'/b.attempting');
            }
        });
    }
    $won = app(SellerRegistrationDecisionService::class)->decide($seller, $admin, $task['decision'], 'Incomplete permit');
    file_put_contents($directory.'/'.$name.'.result.json', json_encode([
        'loaded_status' => $loadedStatus, 'connection' => $connection, 'won' => $won,
        'notifications' => Notification::sent($seller, SellerRegistrationDecision::class)->count(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seller registration worker failed: '.get_class($exception).' (credentials suppressed).');
    exit(3);
}
