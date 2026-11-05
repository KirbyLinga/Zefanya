<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:seed', function () {
    $env = app()->environment();

    if (! in_array($env, ['local', 'demo'], true)) {
        $this->components->error("demo:seed refuses to run in {$env} environment. Allowed: local, demo.");

        return 1;
    }

    $this->call('db:seed', ['--class' => DemoSeeder::class]);

    $this->components->info('Demo data seeded successfully.');
})->purpose('Seed demo data for the presentation build (idempotent, local/demo only)');
