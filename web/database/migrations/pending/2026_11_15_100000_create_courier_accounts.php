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
        if (! Schema::hasTable('transfer_couriers') || ! Schema::hasTable('transfer_courier_assignments')
            || ! Schema::hasColumn('manifests', 'transfer_courier_id')
            || ! Schema::hasColumn('parcel_handoffs', 'transfer_courier_id')) {
            throw new RuntimeException('Apply the Web Transfer Courier prerequisites before enabling Courier accounts.');
        }

        if (Schema::hasTable('courier_accounts')) {
            $this->completeExistingTable();

            return;
        }

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

    private function completeExistingTable(): void
    {
        if (! Schema::hasColumns('courier_accounts', [
            'id', 'transfer_courier_id', 'login_id', 'password', 'status',
            'activation_code_hash', 'activation_expires_at', 'activation_attempts',
            'credential_generation', 'session_generation', 'current_token_id',
            'activated_at', 'last_login_at', 'revoked_at', 'revoked_reason', 'created_at', 'updated_at',
        ])) {
            throw new RuntimeException('Courier accounts has an unexpected schema. Review it before enabling Courier access.');
        }

        foreach (['transfer_courier_id', 'login_id'] as $column) {
            if (! Schema::hasIndex('courier_accounts', [$column], 'unique')) {
                Schema::table('courier_accounts', fn (Blueprint $table) => $table->unique($column));
            }
        }

        $foreignKey = collect(Schema::getForeignKeys('courier_accounts'))
            ->firstWhere('columns', ['transfer_courier_id']);
        if ($foreignKey === null) {
            Schema::table('courier_accounts', fn (Blueprint $table) => $table->foreign('transfer_courier_id')->references('id')->on('transfer_couriers')->restrictOnDelete()
            );

            return;
        }

        if ($foreignKey['foreign_table'] !== 'transfer_couriers' || $foreignKey['foreign_columns'] !== ['id']) {
            throw new RuntimeException('Courier accounts references an unexpected identity table.');
        }
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
