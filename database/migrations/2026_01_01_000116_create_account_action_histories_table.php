<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * account_action_histories - Log of admin suspend and reactivate actions on accounts.
 *
 * Belongs to:
 *   - admins (admin_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('account_action_histories')) {
            return;
        }

        Schema::create('account_action_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('account_type', 20);
            $table->unsignedBigInteger('account_id');
            $table->string('account_name');
            $table->string('account_email');
            $table->string('action', 20);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason');
            $table->timestamp('created_at');

            $table->index(['account_type', 'account_id', 'created_at'], 'account_action_history_lookup_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('account_action_histories');
    }
};
