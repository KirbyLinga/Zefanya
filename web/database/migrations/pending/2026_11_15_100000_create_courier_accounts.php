<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Explicit activation only, after an approved backup:
//   php artisan migrate --path=database/migrations/pending/2026_11_15_100000_create_courier_accounts.php
//
// Courier Mobile app access for an existing Transfer Courier record. Additive:
// no existing table is changed and no row is created. A Courier without an
// account keeps working exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            // One account per operational Courier record; the record cannot be
            // deleted while an account points at it.
            $table->foreignId('transfer_courier_id')->unique()->constrained('transfer_couriers')->restrictOnDelete();
            $table->string('login_id', 20)->unique();
            // Null until activation, and again after a reissue.
            $table->string('password')->nullable();
            $table->string('status', 20)->default('pending_activation');
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->unsignedTinyInteger('activation_attempts')->default(0);
            $table->unsignedInteger('credential_generation')->default(0);
            // The only valid personal access token for this account (no FK: a
            // deleted token row simply stops matching).
            $table->unsignedBigInteger('current_token_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();
            $table->timestamps();
            // Created last on purpose: the runtime readiness probe looks for it,
            // so a partially created table is treated as not ready.
            $table->unsignedBigInteger('session_generation')->default(0);
        });
    }

    public function down(): void
    {
        // Destructive rollback needs separate owner approval. Remove Courier
        // tokens first so no token is left pointing at a dropped model.
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\Logistics\\CourierAccount')
                ->delete();
        }

        Schema::dropIfExists('courier_accounts');
    }
};
