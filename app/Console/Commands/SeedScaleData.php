<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds a separate *_scale database and fills it with ScaleSeeder data. The
 * database name is derived here, never taken from input, so --fresh can only
 * ever drop the scale copy.
 */
class SeedScaleData extends Command
{
    protected $signature = 'scale:seed
        {--tier=medium : small, medium or large}
        {--fresh : Drop and rebuild the scale database first}';

    protected $description = 'Fill a separate *_scale database with large test data';

    public function handle(): int
    {
        $tier = (string) $this->option('tier');
        if (! array_key_exists($tier, ScaleSeeder::TIERS)) {
            $this->error('Choose a tier: '.implode(', ', array_keys(ScaleSeeder::TIERS)).'.');

            return self::FAILURE;
        }

        if ($this->laravel->environment('production')) {
            $this->error('scale:seed does not run in production.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->error('scale:seed needs a MySQL connection.');

            return self::FAILURE;
        }

        $database = ScaleSeeder::databaseNameFor((string) config("database.connections.{$connection}.database"));
        if (preg_match('/^[a-z0-9_]+$/', $database) !== 1) {
            $this->error("Unsafe database name [{$database}].");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::statement("DROP DATABASE IF EXISTS `{$database}`");
        }
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        $this->info("Using database [{$database}], tier [{$tier}].");
        $this->call('migrate', ['--force' => true]);

        $seeder = new ScaleSeeder;
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->run($tier);

        $this->newLine();
        $this->info('Done. Serve this copy on its own port (PowerShell):');
        // MAIL_MAILER=log keeps seeded accounts from reaching the real mail server.
        $this->line("  \$env:DB_DATABASE='{$database}'; \$env:MAIL_MAILER='log'; php artisan serve --port=8123");
        $this->info('Logins use the password "'.ScaleSeeder::PASSWORD.'":');
        $this->line('  scale.buyer1@example.test, scale.seller1@example.test, scale.logistics1@example.test, scale.rider1@example.test, scale.admin@example.test');

        return self::SUCCESS;
    }
}
