<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

final class Race
{
    public static array $evidence = [];

    public static function waitFor(string $file, array $processes): void
    {
        $deadline = microtime(true) + 18;
        while (! is_file($file)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $resultFile = dirname($file).'/'.basename($file)[0].'.result.json';
                    $result = is_file($resultFile) ? json_decode(file_get_contents($resultFile), true) : [];
                    if ($process->getExitCode() === 2 || ($result['preflight_failed'] ?? false)) {
                        foreach ($processes as $other) {
                            if ($other->isRunning()) {
                                $other->stop(0);
                            }
                        }
                        Preflight::halt();
                    }
                    throw new RuntimeException('Worker exited before synchronization: '.basename($file));
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Synchronization deadline: '.basename($file));
            }
            usleep(10000);
        }
    }

    public static function single(array $task): array
    {
        $directory = dirname(__DIR__, 2).'/.codex/t05-runs/'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $task += ['directory' => $directory, 'name' => 'a'];
        file_put_contents($directory.'/a.task.json', json_encode($task));
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $directory.'/a.task.json'], dirname(__DIR__, 2));
        $process->setTimeout(25);
        $process->run();
        $file = $directory.'/a.result.json';
        if ($process->getExitCode() === 2 || ! is_file($file)) {
            Preflight::halt();
        }
        $result = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if ($result['preflight_failed'] ?? false) {
            Preflight::halt();
        }

        return $result;
    }

    public static function run(array $a, array $b, bool $bothHeld = false, bool $rejectionWithoutLock = false): array
    {
        $runRoot = match ($a['action'] ?? '') {
            'dispatch' => '/storage/logs/phase6-races/', 'payout_request', 'settlement' => '/storage/logs/payout-races/', 'return_request', 'return_receipt' => '/storage/logs/phase7-races/', default => '/.codex/t05-runs/'
        };
        $directory = dirname(__DIR__, 2).$runRoot.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $processes = [];
        foreach (['a' => $a, 'b' => $b] as $name => $task) {
            $task += ['directory' => $directory, 'name' => $name];
            file_put_contents($directory.'/'.$name.'.task.json', json_encode($task));
            $processes[$name] = new Process([PHP_BINARY, __DIR__.'/worker.php', $directory.'/'.$name.'.task.json'], dirname(__DIR__, 2));
            $processes[$name]->setTimeout(35);
        }
        try {
            $processes['a']->start();
            self::waitFor($directory.'/a.held', [$processes['a']]);
            $processes['b']->start();
            $observed = null;
            if ($bothHeld) {
                self::waitFor($directory.'/b.held', $processes);
            } elseif ($rejectionWithoutLock) {
                self::waitFor($directory.'/b.result.json', $processes);
                $early = json_decode(file_get_contents($directory.'/b.result.json'), true);
                if ($early['preflight_failed'] ?? false) {
                    foreach ($processes as $process) {
                        if ($process->isRunning()) {
                            $process->stop(0);
                        }
                    }
                    Preflight::halt();
                }
            } else {
                self::waitFor($directory.'/b.attempting', $processes);
                $id = (int) file_get_contents($directory.'/b.connection');
                $deadline = microtime(true) + 8;
                while ($observed === null && microtime(true) < $deadline) {
                    foreach (DB::connection()->getPdo()->query('SHOW FULL PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        $sql = strtolower((string) ($row['Info'] ?? ''));
                        $target = match ($b['watch'] ?? '') {
                            'parcel' => str_contains($sql, 'from `seller_orders`') && str_contains($sql, 'for update'),
                            'provider' => str_contains($sql, 'from `logistics_providers`') && str_contains($sql, 'for update'),
                            'product' => str_contains($sql, 'from `products`') && str_contains($sql, 'for update'),
                            'variant' => str_contains($sql, 'from `product_variants`') && str_contains($sql, 'for update'),
                            'seller' => str_contains($sql, 'from `sellers`') && str_contains($sql, 'for update'),
                            'token' => str_starts_with($sql, 'update `checkout_tokens`'),
                            'cartwrite' => str_starts_with($sql, 'update `cart_items`'),
                            default => false,
                        };
                        if ((int) $row['Id'] === $id && $target
                            && in_array($row['Command'], ['Query', 'Execute'], true)
                            && ! is_file($directory.'/b.passed')) {
                            $observed = ['connection' => $id, 'state' => $row['State'], 'query_in_flight' => true];
                            break;
                        }
                    }
                    usleep(10000);
                }
                if ($observed === null) {
                    throw new RuntimeException('No overlapping blocked MySQL statement observed.');
                }
            }
            touch($directory.'/a.release');
            if ($bothHeld) {
                touch($directory.'/b.release');
            }
            foreach ($processes as $process) {
                $process->wait();
            }
            $results = [];
            foreach (['a', 'b'] as $name) {
                $file = $directory.'/'.$name.'.result.json';
                if (! is_file($file)) {
                    throw new RuntimeException('Missing worker result: '.$name);
                }
                $results[$name] = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (($results[$name]['preflight_failed'] ?? false)) {
                    Preflight::halt();
                }
                if (isset($results[$name]['exception'])) {
                    throw new RuntimeException('Worker '.$name.' failed: '.$results[$name]['exception']);
                }
            }
            if ($results['a']['connection'] === $results['b']['connection']) {
                throw new RuntimeException('Race did not use independent database sessions.');
            }
            if (! ($results['a']['start'] < $results['b']['start'] && $results['b']['start'] < $results['a']['end'])) {
                throw new RuntimeException('Worker execution intervals did not overlap.');
            }
            $evidence = ['directory' => basename($directory), 'observed_overlap' => $observed,
                'a_transaction' => json_decode(file_get_contents($directory.'/a.held'), true),
                'b_transaction' => is_file($directory.'/b.held')
                    ? json_decode(file_get_contents($directory.'/b.held'), true)
                    : (is_file($directory.'/b.attempting') ? json_decode(file_get_contents($directory.'/b.attempting'), true) : null),
                'both_transactions_held' => $bothHeld, 'results' => $results];
            self::$evidence[] = $evidence;
            file_put_contents($directory.'/evidence.json', json_encode($evidence, JSON_PRETTY_PRINT));

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }
}
