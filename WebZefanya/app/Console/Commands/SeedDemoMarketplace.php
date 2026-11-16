<?php

namespace App\Console\Commands;

use App\Models\Admin\Admin;
use Database\Seeders\AdminSeeder;
use Database\Seeders\Demo\DemoMarketplaceSeeder;
use Database\Seeders\Demo\DemoReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds a separate *_demo database and fills it with the demo marketplace.
 * The database name is derived here from the configured one, never typed in,
 * and nothing is ever dropped or truncated, so the live database is untouched.
 */
class SeedDemoMarketplace extends Command
{
    protected $signature = 'demo:seed
        {--accounts : Also write docs/DEMO-ACCOUNTS.md and docs/demo-accounts-riders.csv}';

    protected $description = 'Fill a separate *_demo database with the demo marketplace and logistics network';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:seed does not run in production. Set APP_ENV=local for this command only (PowerShell: $env:APP_ENV=\'local\').');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->error('demo:seed needs a MySQL connection.');

            return self::FAILURE;
        }

        $base = (string) config("database.connections.{$connection}.database");
        $database = str_ends_with($base, '_demo') ? $base : $base.'_demo';
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            $this->error("Unsafe database name [{$database}].");

            return self::FAILURE;
        }

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        $this->info("Using database [{$database}].");
        $this->call('migrate', ['--force' => true]);

        if (Admin::query()->doesntExist()) {
            $this->call('db:seed', ['--class' => AdminSeeder::class, '--force' => true]);
        }

        $seeder = new DemoMarketplaceSeeder;
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->run();

        $this->table(
            ['Province', 'Cities/Munis', 'Barangays', 'Stations', 'Riders', 'Couriers', 'Buyers', 'Sellers', 'Products', 'Coverage %'],
            array_map(fn (array $row): array => array_values($row), DemoReport::counts()),
        );

        if ($this->option('accounts')) {
            file_put_contents(base_path('docs/DEMO-ACCOUNTS.md'), DemoReport::accountsMarkdown());
            file_put_contents(base_path('docs/demo-accounts-riders.csv'), DemoReport::ridersCsv());
            $this->info('Wrote docs/DEMO-ACCOUNTS.md and docs/demo-accounts-riders.csv.');
        }

        $this->newLine();
        $this->info('Done. Serve this copy on its own port (PowerShell):');
        $this->line("  \$env:APP_ENV='local'; \$env:DB_DATABASE='{$database}'; \$env:MAIL_MAILER='log'; php artisan serve --port=8124");

        return self::SUCCESS;
    }
}
