<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_setting_histories - History of every settings change.
 *
 * Belongs to:
 *   - admins (admin_id)
 *   - platform_settings (platform_setting_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('platform_setting_histories')) {
            return;
        }

        Schema::create('platform_setting_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_setting_id')->constrained('platform_settings')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('setting_key', 80);
            $table->text('previous_value');
            $table->text('new_value');
            $table->timestamp('created_at');

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('platform_setting_histories');
    }
};
