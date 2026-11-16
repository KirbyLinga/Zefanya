<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cod_restriction_lifts - Admin lifting a buyer's cash-on-delivery restriction.
 *
 * Belongs to:
 *   - admins (admin_id)
 *   - buyers (buyer_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('cod_restriction_lifts')) {
            return;
        }

        Schema::create('cod_restriction_lifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();
            $table->string('phone_key', 10)->nullable();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedSmallInteger('strikes_cleared');
            $table->timestamp('lifted_at');
            $table->timestamps();

            $table->index(['buyer_id', 'lifted_at']);
            $table->index(['phone_key', 'lifted_at']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('cod_restriction_lifts');
    }
};
