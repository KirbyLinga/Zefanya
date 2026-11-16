<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_loss_adjustments - Later corrections to a loss payment.
 *
 * Belongs to:
 *   - parcel_loss_cases (parcel_loss_case_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_loss_adjustments')) {
            return;
        }

        Schema::create('parcel_loss_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_loss_case_id')->constrained('parcel_loss_cases')->restrictOnDelete();
            $table->unsignedBigInteger('admin_id');
            $table->bigInteger('amount_minor');
            $table->string('reason', 1000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_loss_adjustments');
    }
};
