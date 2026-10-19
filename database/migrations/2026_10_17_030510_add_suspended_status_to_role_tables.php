<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3: admins can suspend an approved buyer/seller/logistics account.
 * SQLite stores status as TEXT (no ENUM enforcement); MySQL needs ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY status ENUM('unverified', 'pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'unverified'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            DB::table($table)->where('status', 'suspended')->update(['status' => 'approved']);
            DB::statement("ALTER TABLE {$table} MODIFY status ENUM('unverified', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'unverified'");
        }
    }
};
