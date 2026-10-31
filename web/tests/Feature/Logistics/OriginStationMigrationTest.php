<?php

namespace Tests\Feature\Logistics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OriginStationMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_assignments_without_guessing_history_and_rolls_back(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $original = config('database.default');
        $path = tempnam(sys_get_temp_dir(), 'zefanya-origin-');
        $backup = $path.'.backup';
        config(['database.connections.origin_migration_test' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'database.default' => 'origin_migration_test']);

        try {
            $target = DB::select('PRAGMA database_list')[0]->file;
            $this->assertSame(realpath($path), realpath($target));
            Schema::create('logistics_hubs', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('riders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('station_hub_id')->nullable();
            });
            Schema::create('couriers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rider_id');
            });
            Schema::create('seller_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('destination_area_id')->nullable();
                $table->unsignedBigInteger('courier_id')->nullable();
                $table->unsignedBigInteger('delivery_courier_id')->nullable();
            });
            DB::table('logistics_hubs')->insert([['id' => 1], ['id' => 2]]);
            DB::table('riders')->insert(['id' => 1, 'station_hub_id' => 2]);
            DB::table('couriers')->insert(['id' => 7, 'rider_id' => 1]);
            DB::table('seller_orders')->insert(['id' => 9, 'courier_id' => 7, 'delivery_courier_id' => 7]);
            DB::disconnect('origin_migration_test');
            $this->assertTrue(copy($path, $backup));
            $this->assertSame(hash_file('sha256', $path), hash_file('sha256', $backup));
            file_put_contents(storage_path('logs/f2-origin-migration.json'), json_encode([
                'environment' => app()->environment(), 'driver' => DB::connection()->getDriverName(),
                'target' => $target, 'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            // Only execute after confirming this unique test target and its backup.
            $migration = require database_path('migrations/2026_10_21_124707_add_origin_station_hub_id_to_seller_orders_table.php');
            $migration->up();
            $parcel = DB::table('seller_orders')->find(9);
            $this->assertNull($parcel->origin_station_hub_id);
            $this->assertSame(7, $parcel->courier_id);
            $this->assertSame(7, $parcel->delivery_courier_id);
            DB::table('seller_orders')->where('id', 9)->update(['origin_station_hub_id' => 1]);
            $migration->up();
            $this->assertSame(1, DB::table('seller_orders')->find(9)->origin_station_hub_id);

            $migration->down();
            $this->assertFalse(Schema::hasColumn('seller_orders', 'origin_station_hub_id'));
            $this->assertSame(7, DB::table('seller_orders')->find(9)->courier_id);
            $migration->up();
            $this->assertNull(DB::table('seller_orders')->find(9)->origin_station_hub_id);
        } finally {
            DB::purge('origin_migration_test');
            config(['database.default' => $original]);
            // Keep the isolated database and verified backup as validation artifacts.
        }
    }
}
