<?php

use Tests\MySql\Preflight;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';
require_once __DIR__.'/Race.php';

try {
    Preflight::configure();
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=zefanya_concurrency_test;charset=utf8mb4',
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    Preflight::verify($pdo);
} catch (Throwable $exception) {
    fwrite(STDERR, 'T05 PREFLIGHT FAILED: '.get_class($exception)." (credentials suppressed).\n");
    exit(2);
}
