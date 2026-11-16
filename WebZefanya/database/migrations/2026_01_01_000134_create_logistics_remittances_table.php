<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_remittances - A company's payment of collected cash to Zefanya.
 *
 * Belongs to:
 *   - admins (decided_by_admin_id) - optional
 *   - logistics_providers (logistics_provider_id)
 * Used by: cod_remittances
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_remittances')) {
            return;
        }

        Schema::create('logistics_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('parcel_count');
            $table->unsignedBigInteger('cod_total_minor');
            $table->unsignedBigInteger('shipping_total_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->string('status', 20)->default('SUBMITTED');
            $table->unsignedBigInteger('received_amount_minor')->nullable();
            $table->unsignedBigInteger('short_minor')->default(0);
            $table->unsignedInteger('left_out_count')->default(0);
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['logistics_provider_id', 'id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_remittances');
    }
};
