<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * password_reset_tokens_buyers - Forgot-password links for buyers.
 *
 * Belongs to: nothing (a starting table).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('password_reset_tokens_buyers')) {
            return;
        }

        Schema::create('password_reset_tokens_buyers', function (Blueprint $table): void {
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary('email');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('password_reset_tokens_buyers');
    }
};
