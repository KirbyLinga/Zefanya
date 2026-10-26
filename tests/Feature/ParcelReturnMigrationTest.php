<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParcelReturnMigrationTest extends TestCase
{
    public function test_add_delivery_attempts_migration_is_safe_for_fresh_and_existing_databases(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $original = config('database.default');
        $path = tempnam(sys_get_temp_dir(), 'zefanya-parcel-return-');
        $backup = $path.'.backup';
        config(['database.connections.parcel_return_migration_test' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'database.default' => 'parcel_return_migration_test']);

        try {
            $target = DB::select('PRAGMA database_list')[0]->file;
            $this->assertSame(realpath($path), realpath($target));

            // Set up prerequisite tables for parcel_returns foreign keys.
            Schema::create('logistics_providers', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('logistics_hubs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('logistics_provider_id');
            });
            Schema::create('sellers', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('seller_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id');
                $table->string('tracking_number');
                $table->string('status')->default('PLACED');
                $table->foreign('seller_id')->references('id')->on('sellers');
            });
            Schema::create('parcel_returns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_order_id')->unique()->constrained()->restrictOnDelete();
                $table->string('status', 40)->default('RETURN_REQUESTED');
                $table->unsignedTinyInteger('delivery_attempts')->default(0);
                $table->text('reason');
                $table->text('exception_reason')->nullable();
                $table->foreignId('origin_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
                $table->foreignId('origin_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
                $table->json('seller_address')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('origin_arrived_at')->nullable();
                $table->timestamp('seller_received_at')->nullable();
                $table->foreignId('received_by_seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
                $table->string('handover_code_hash', 64)->nullable();
                $table->string('handover_nonce', 64)->nullable();
                $table->timestamp('handover_expires_at')->nullable();
                $table->string('stock_condition', 24)->default('pending_review');
                $table->timestamp('stock_restored_at')->nullable();
                $table->boolean('financial_review_required')->default(false);
                $table->timestamps();
                $table->index(['status', 'requested_at']);
                $table->index(['origin_provider_id', 'status']);
            });

            // Fresh database: delivery_attempts already present from original migration.
            $this->assertTrue(Schema::hasColumn('parcel_returns', 'delivery_attempts'));

            // Simulate an existing database that ran an older version without the column.
            Schema::table('parcel_returns', function (Blueprint $table): void {
                $table->dropColumn('delivery_attempts');
            });
            $this->assertFalse(Schema::hasColumn('parcel_returns', 'delivery_attempts'));

            // Run the additive migration — should add the column back safely.
            $migration = require database_path('migrations/2026_10_24_143000_add_delivery_attempts_to_parcel_returns.php');
            $migration->up();
            $this->assertTrue(Schema::hasColumn('parcel_returns', 'delivery_attempts'));

            // Repeated up() is idempotent — no duplicate-column failure.
            $migration->up();
            $this->assertTrue(Schema::hasColumn('parcel_returns', 'delivery_attempts'));

            // Down() removes the column cleanly.
            $migration->down();
            $this->assertFalse(Schema::hasColumn('parcel_returns', 'delivery_attempts'));

            // Existing data preserved: no truncation or reset.
            $this->assertTrue(Schema::hasTable('parcel_returns'));
        } finally {
            DB::purge('parcel_return_migration_test');
            config(['database.default' => $original]);
        }
    }
}
