<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * checkout_tokens - One-time tokens that stop a checkout being submitted twice.
 *
 * Belongs to:
 *   - buyers (buyer_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('checkout_tokens')) {
            return;
        }

        Schema::create('checkout_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64);
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->json('shipping_rates_snapshot')->nullable();

            $table->index(['buyer_id', 'used']);
            $table->index('expires_at');
            $table->unique('token');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('checkout_tokens');
    }
};
