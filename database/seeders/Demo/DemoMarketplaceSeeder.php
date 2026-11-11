<?php

namespace Database\Seeders\Demo;

use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Runs the whole demo marketplace and logistics network, in order:
 *
 *  1. DeliveryScenarioSeeder   the original five-scenario network (reused as is)
 *  2. DemoCoverageSeeder       every city/municipality and barangay of the six areas
 *  3. DemoRiderFleetSeeder     Riders so every barangay has one (at most 3 barangays each)
 *  4. DemoTransportNetworkSeeder  station/Province Hub routes, partnerships, transport Couriers
 *  5. DemoRoleAccountsSeeder   Sellers and Buyers with complete profiles
 *  6. DemoCatalogSeeder        products with licensed photographs, stock and sales
 *
 * Every step finds what exists and adds only what is missing, so running this
 * again changes nothing. It creates no orders, payments, COD, payouts or
 * notifications. It refuses to run anywhere but a database named for demo or
 * test use; see assertSafeTarget().
 */
class DemoMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        self::assertSafeTarget();

        foreach ([
            DeliveryScenarioSeeder::class,
            DemoCoverageSeeder::class,
            DemoRiderFleetSeeder::class,
            DemoTransportNetworkSeeder::class,
            DemoRoleAccountsSeeder::class,
            DemoCatalogSeeder::class,
        ] as $seeder) {
            $this->command?->info('Seeding '.class_basename($seeder).'...');
            $this->call($seeder);
        }
    }

    /**
     * The demo seeders write thousands of rows, so they only run against a
     * database that is plainly not the live one: an in-memory test database,
     * a name ending in _demo, or the disposable isolated test database.
     */
    public static function assertSafeTarget(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The demo marketplace seeders refuse to run in production.');
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        // Seeding a named, non-demo database (such as the school project's own) needs the
        // process to say its exact name: DEMO_SEED_CONFIRM_LIVE=<database>. It is never read from .env.
        $confirmed = $database !== '' && getenv('DEMO_SEED_CONFIRM_LIVE') === $database;

        $allowed = ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && (preg_match('/(_demo|_concurrency_test)$/', $database) === 1 || $confirmed));

        if (! $allowed) {
            throw new RuntimeException("Refusing to seed database [{$database}]: use a database whose name ends in _demo (php artisan demo:seed creates one).");
        }
    }
}
