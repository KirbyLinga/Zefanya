<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use Symfony\Component\Process\Process;
use Tests\MySql\Preflight;

$lock = fopen(dirname(__DIR__, 2).'/.codex/t05-runner.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
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
        if (! str_starts_with($argument, '--filter=') && ! in_array($argument, ['--testdox', '--no-progress'], true)) {
            throw new RuntimeException('Unsupported runner argument.');
        }
    }
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/phpunit/phpunit/phpunit',
        '--configuration', dirname(__DIR__, 2).'/phpunit.mysql.xml', ...$arguments], dirname(__DIR__, 2));
    $process->setTimeout(240);
    exit($process->run(fn ($type, $buffer) => print ($buffer)));
} catch (Throwable $exception) {
    fwrite(STDERR, 'T05 stopped: '.get_class($exception)." (credentials suppressed).\n");
    exit(2);
}
