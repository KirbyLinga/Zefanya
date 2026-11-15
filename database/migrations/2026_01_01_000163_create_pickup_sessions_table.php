<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pickup_sessions - A rider's pickup run at one seller.
 *
 * Belongs to:
 *   - couriers (courier_id)
 *   - logistics_providers (logistics_provider_id)
 *   - sellers (seller_id)
 * Used by: rider_earnings, seller_orders
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('pickup_sessions')) {
            return;
        }

        Schema::create('pickup_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->string('pickup_address_hash', 40);
            $table->string('pickup_address', 500)->nullable();
            $table->string('pickup_barangay_code', 20)->nullable();
            $table->string('window_key', 20);
            $table->timestamp('window_starts_at');
            $table->timestamp('window_ends_at');
            $table->string('status', 20)->default('OPEN');
            $table->unsignedInteger('first_minor');
            $table->unsignedInteger('additional_minor');
            $table->boolean('first_payable')->default(true);
            $table->unsignedBigInteger('continuation_of_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 40)->nullable();
            $table->string('open_key', 191)->nullable();
            $table->timestamps();

            $table->index(['courier_id', 'status']);
            $table->unique('open_key');
            $table->index(['seller_id', 'courier_id', 'closed_at']);
            $table->index(['status', 'window_ends_at']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('pickup_sessions');
    }
};
