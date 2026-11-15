<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * allocation_policy_approvals - Admin approval of how money is split when three or more companies handle a parcel.
 *
 * Belongs to:
 *   - admins (admin_id)
 * Used by: parcel_provider_allocations
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('allocation_policy_approvals')) {
            return;
        }

        Schema::create('allocation_policy_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->text('policy_note');
            $table->timestamp('approved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('allocation_policy_approvals');
    }
};
