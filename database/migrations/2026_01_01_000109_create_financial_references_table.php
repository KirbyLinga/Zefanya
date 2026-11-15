<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * financial_references - Registry that stops a payment reference being used twice.
 *
 * Belongs to:
 *   - financial_references (reuses_id) [itself] - one-to-one - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('financial_references')) {
            return;
        }

        Schema::create('financial_references', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20);
            $table->string('reference');
            $table->string('normalized');
            $table->string('active_key')->nullable();
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('recipient', 80)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('destination')->nullable();
            $table->string('evidence', 120)->nullable();
            $table->foreignId('reuses_id')->nullable()->constrained('financial_references')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'active_key'], 'financial_references_active_unique');
            $table->unique('reuses_id');
            $table->index(['scope', 'normalized']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('financial_references');
    }
};
