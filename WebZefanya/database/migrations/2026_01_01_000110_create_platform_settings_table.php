<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_settings - Admin-editable settings (platform name, pickup windows, rider rates).
 *
 * Belongs to: nothing (a starting table).
 * Used by: platform_setting_histories
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('platform_settings')) {
            return;
        }

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->text('value');
            $table->timestamps();

            $table->unique('key');
        });

        // Reference rows every install needs.
        DB::table('platform_settings')->insert(array_map(fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()], [
            ['id' => 1, 'key' => 'platform_name', 'value' => 'Zefanya'],
            ['id' => 2, 'key' => 'contact_email', 'value' => 'ninjaexpress.demo@example.com'],
            ['id' => 3, 'key' => 'remittance_payment_details', 'value' => ''],
            ['id' => 4, 'key' => 'pickup_window_morning', 'value' => '08:00-12:00'],
            ['id' => 5, 'key' => 'pickup_window_afternoon', 'value' => '13:00-17:00'],
            ['id' => 6, 'key' => 'rider_pickup_first_rate', 'value' => '30.00'],
            ['id' => 7, 'key' => 'rider_pickup_additional_rate', 'value' => '5.00'],
            ['id' => 8, 'key' => 'rider_delivery_rate', 'value' => '50.00'],
        ]));
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('platform_settings');
    }
};
