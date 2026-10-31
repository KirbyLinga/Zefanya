<?php

use App\Models\Buyer\Buyer;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\AutomaticBuyerReceiptService;
use App\Services\Logistics\ParcelReturnService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Preflight;

require __DIR__.'/bootstrap.php';
$app = Preflight::application();
$task = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
Carbon::setTestNow(Carbon::parse($task['now'], 'UTC'));
CarbonImmutable::setTestNow(CarbonImmutable::parse($task['now'], 'UTC'));
$directory = $task['directory'];
$connection = DB::connection();
if ($task['name'] === 'b') {
    $connection->beforeExecuting(function ($sql) use ($directory, $connection): void {
        if (str_contains(strtolower($sql), 'seller_orders') && str_contains(strtolower($sql), 'for update')) {
            file_put_contents($directory.'/b.attempting', (string) $connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        }
    });
}
$operation = function () use ($task): string {
    if ($task['action'] === 'return') {
        app(ParcelReturnService::class)->requestByBuyer(SellerOrder::query()->findOrFail($task['id']), Buyer::query()->findOrFail($task['buyer']), 'Concurrent Buyer return');

        return 'success';
    }

    return app(AutomaticBuyerReceiptService::class)->complete($task['id'], CarbonImmutable::now()->subHours(168)) ? 'success' : 'skipped';
};
try {
    if ($task['name'] === 'a') {
        $result = DB::transaction(function () use ($task, $directory, $operation): string {
            SellerOrder::query()->whereKey($task['id'])->lockForUpdate()->firstOrFail();
            touch($directory.'/a.held');
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/release')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('RULE-05 release signal timed out.');
                }
                usleep(10000);
            }

            return $operation();
        });
    } else {
        $result = $operation();
    }
    file_put_contents($directory.'/'.$task['name'].'.result.json', json_encode(['result' => $result]));
} catch (ValidationException) {
    file_put_contents($directory.'/'.$task['name'].'.result.json', json_encode(['result' => 'rejected']));
} catch (Throwable $exception) {
    fwrite(STDERR, 'RULE-05 worker failed: '.get_class($exception));
    exit(1);
}
