<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_return_events - The steps of a return.
 *
 * Belongs to:
 *   - parcel_returns (parcel_return_id)
 * Used by: platform_refund_expenses
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_return_events')) {
            return;
        }

        Schema::create('parcel_return_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_return_id')->constrained('parcel_returns')->restrictOnDelete();
            $table->string('event_key', 40);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['parcel_return_id', 'event_key']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_return_events');
    }
};
