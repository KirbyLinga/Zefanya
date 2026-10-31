<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Preflight;

$task = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$directory = $task['directory'];
$name = $task['name'];
$wait = static function (string $file): void {
    $deadline = microtime(true) + 20;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Registration email worker synchronization deadline.');
        }
        usleep(10000);
    }
};
try {
    Preflight::application();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Registration email MySQL preflight failed: '.get_class($exception).' (credentials suppressed).');
    exit(2);
}
try {
    $connection = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    file_put_contents($directory.'/'.$name.'.connection', (string) $connection);
    touch($directory.'/'.$name.'.ready');
    $wait($directory.'/'.$name.'.go');
    if ($name === 'a') {
        DB::listen(static function (QueryExecuted $event) use ($directory, $wait): void {
            if (str_contains(strtolower($event->sql), 'get_lock')) {
                touch($directory.'/a.held');
                $wait($directory.'/a.release');
            }
        });
    } else {
        DB::connection()->beforeExecuting(static function (string $sql) use ($directory): void {
            if (str_contains(strtolower($sql), 'get_lock')) {
                touch($directory.'/b.attempting');
            }
        });
    }
    $model = match ($task['role']) {
        'buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class,
    };
    try {
        EmailRegistry::register($task['email'], $task['role'], static function () use ($model, $task) {
            $attributes = ['email' => strtolower($task['email']), 'status' => 'unverified', 'email_verified_at' => null, 'email_verification_expires_at' => now()->addMinutes(10)];
            if ($task['role'] === 'seller') {
                $attributes['line_of_business_id'] = null;
            }

            return $model::factory()->create($attributes);
        });
        $won = true;
        $errors = [];
    } catch (ValidationException $exception) {
        $won = false;
        $errors = $exception->errors();
    }
    file_put_contents($directory.'/'.$name.'.result.json', json_encode(['connection' => $connection, 'won' => $won, 'errors' => $errors], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Registration email worker failed: '.get_class($exception).' (credentials suppressed).');
    exit(3);
}
