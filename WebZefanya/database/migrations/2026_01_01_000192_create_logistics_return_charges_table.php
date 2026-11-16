<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_return_charges - Return shipping fees charged to a company.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - parcel_returns (parcel_return_id)
 *   - seller_orders (seller_order_id)
 *   - admins (settled_by_admin_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_return_charges')) {
            return;
        }

        Schema::create('logistics_return_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('parcel_return_id')->constrained('parcel_returns')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('reason', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->string('settlement_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['logistics_provider_id', 'settled_at']);
            $table->unique(['parcel_return_id', 'reason']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_return_charges');
    }
};
