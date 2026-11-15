<?php

namespace Database\Seeders\Demo;

use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        self::assertSafeConnection();

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
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo marketplace seeders only run in local or testing environments.');
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        $allowed = ($driver === 'sqlite' && $database === ':memory:' && app()->environment('testing'))
            || ($driver === 'mysql' && preg_match('/\A[a-z0-9_]+(_demo|_concurrency_test)\z/i', $database) === 1);

        if (! $allowed) {
            throw new RuntimeException("Refusing to seed database [{$database}]: use a dedicated demo or test database.");
        }
    }

    /** Check the resolved connection before any direct or aggregate seeder can write. */
    public static function assertSafeConnection(): void
    {
        self::assertSafeTarget();
        $db = DB::connection();
        $configured = config('database.connections.'.config('database.default'));
        if ($db->getDriverName() === 'sqlite' && $configured['driver'] === 'sqlite'
            && $db->getDatabaseName() === ':memory:') {
            return;
        }

        $database = $configured['database'];
        if ($db->getDriverName() !== 'mysql' || $configured['driver'] !== 'mysql'
            || ! empty($configured['url']) || ! empty($configured['read']) || ! empty($configured['write'])
            || ! empty($configured['unix_socket'])
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || getenv('DEMO_SEED_DATABASE') !== $database
            || (int) $db->getConfig('port') === 3306
            || $db->selectOne('SELECT DATABASE() AS db', [], false)?->db !== $database
            || $db->table('orders')->useWritePdo()->exists()) {
            throw new RuntimeException('Demo seeding requires an explicitly named, disposable local MySQL schema with no orders on a separate port.');
        }

        // A suffix alone does not make a restored database disposable.
        foreach (['buyers', 'sellers', 'logistics_providers', 'riders'] as $table) {
            if ($db->table($table)->useWritePdo()->where(fn ($query) => $query->whereNull('email')
                ->orWhere('email', 'not like', '%@zefanya.test'))->exists()) {
                throw new RuntimeException('Demo seeding refuses a schema containing non-demo marketplace accounts.');
            }
        }
    }
}
