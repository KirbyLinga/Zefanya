<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use Symfony\Component\Process\Process;
use Tests\MySql\Preflight;

$directory = dirname(__DIR__, 2).'/.codex';
if (! is_file($directory.'/t05.env') && getenv('T05_SERVER_UUID') === false) {
    fwrite(STDERR, "T05 requires an explicit disposable MySQL configuration.\n");
    exit(2);
}
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "T05 could not create its test lock directory.\n");
    exit(2);
}
$lock = fopen($directory.'/t05-runner.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another T05 runner owns the disposable database.\n");
    exit(2);
}
try {
    Preflight::application();
    echo "T05 preflight passed: authorized MySQL server, restricted account, application access denied.\n";
    if (in_array('--preflight-only', $argv, true)) {
        exit(0);
    }
    $arguments = array_slice($argv, 1);
    foreach ($arguments as $argument) {
        if (! str_starts_with($argument, '--filter=') && ! in_array($argument, ['--testdox', '--no-progress', '--debug'], true)) {
            throw new RuntimeException('Unsupported runner argument.');
        }
    }
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/phpunit/phpunit/phpunit',
        '--configuration', dirname(__DIR__, 2).'/phpunit.mysql.xml', ...$arguments], dirname(__DIR__, 2));
    // Each case cleans the isolated InnoDB schema and boots independent workers.
    // The worker and SQL lock deadlines still bound each individual race.
    $process->setTimeout(1800);
    exit($process->run(fn ($type, $buffer) => print ($buffer)));
} catch (Throwable $exception) {
    fwrite(STDERR, 'T05 stopped: '.get_class($exception)." (credentials suppressed).\n");
    exit(2);
}
