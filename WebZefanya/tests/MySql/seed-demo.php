<?php

/**
 * Seeds the demo marketplace into the disposable isolated MySQL database
 * (zefanya_concurrency_test) behind the same preflight the MySQL tests use,
 * then prints the per-area counts and writes the account directory.
 *
 *   php tests/MySql/seed-demo.php [--accounts]
 *
 * It never touches the live `Zefanya` database: Preflight refuses any server,
 * account or schema other than the owned disposable one. It does not truncate
 * or drop anything; run it twice to see idempotency.
 */

use App\Models\Admin\Admin;
use Database\Seeders\Demo\DemoMarketplaceSeeder;
use Database\Seeders\Demo\DemoReport;
use Illuminate\Support\Facades\Storage;
use Tests\MySql\Preflight;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

$lock = fopen(dirname(__DIR__, 2).'/.codex/t05-runner.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another T05 runner owns the disposable database.\n");
    exit(2);
}

try {
    $app = Preflight::application();
    Preflight::prepareSchema();

    // Files go to a scratch disk root so the isolated run never writes into storage/app/public.
    $root = dirname(__DIR__, 2).'/storage/app/demo-isolated';
    config(['filesystems.disks.public.root' => $root.'/public', 'filesystems.disks.local.root' => $root.'/private']);
    Storage::forgetDisk(['public', 'local']);

    if (Admin::query()->doesntExist()) {
        Admin::factory()->create();
    }

    $started = microtime(true);
    $seeder = new DemoMarketplaceSeeder;
    $seeder->setContainer($app);
    $seeder->run();
    printf("Seeded in %.1fs\n\n", microtime(true) - $started);

    $rows = DemoReport::counts();
    echo str_pad('Province', 28).str_pad('Cities', 8).str_pad('Brgys', 8).str_pad('Stations', 10).str_pad('Riders', 8).str_pad('Couriers', 10).str_pad('Buyers', 8).str_pad('Sellers', 9).str_pad('Products', 10)."Coverage%\n";
    foreach ($rows as $r) {
        echo str_pad($r['area'], 28).str_pad((string) $r['municipalities'], 8).str_pad((string) $r['barangays'], 8).str_pad((string) $r['stations'], 10).str_pad((string) $r['riders'], 8).str_pad((string) $r['couriers'], 10).str_pad((string) $r['buyers'], 8).str_pad((string) $r['sellers'], 9).str_pad((string) $r['products'], 10).$r['coverage']."\n";
    }

    if (in_array('--accounts', $argv, true)) {
        file_put_contents(dirname(__DIR__, 2).'/docs/DEMO-ACCOUNTS.md', DemoReport::accountsMarkdown());
        file_put_contents(dirname(__DIR__, 2).'/docs/demo-accounts-riders.csv', DemoReport::ridersCsv());
        echo "\nWrote docs/DEMO-ACCOUNTS.md and docs/demo-accounts-riders.csv\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Demo seed stopped: '.get_class($exception).': '.$exception->getMessage()."\n");
    exit(2);
}
