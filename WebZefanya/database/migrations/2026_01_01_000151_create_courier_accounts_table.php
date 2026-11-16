<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * courier_accounts - Mobile-app login for a transfer courier.
 *
 * Belongs to:
 *   - transfer_couriers (transfer_courier_id) - one-to-one
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('courier_accounts')) {
            return;
        }

        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_courier_id')->constrained('transfer_couriers')->restrictOnDelete();
            $table->string('login_id', 20);
            $table->string('password')->nullable();
            $table->string('status', 20)->default('pending_activation');
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->unsignedTinyInteger('activation_attempts')->default(0);
            $table->unsignedInteger('credential_generation')->default(0);
            $table->unsignedBigInteger('current_token_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('session_generation')->default(0);

            $table->unique('login_id');
            $table->unique('transfer_courier_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('courier_accounts');
    }
};
