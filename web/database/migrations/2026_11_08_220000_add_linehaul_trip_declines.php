<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('offer_version')->default(1);
            $table->timestamp('declined_at')->nullable();
        });
        Schema::create('linehaul_trip_declines', function (Blueprint $table): void {
            $table->id();
            // Keep the immutable snapshot if Logistics deletes the draft batch.
            $table->unsignedBigInteger('manifest_id');
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('linehaul_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->unsignedInteger('offer_version');
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['manifest_id', 'offer_version']);
            $table->unique(['manifest_id', 'linehaul_driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linehaul_trip_declines');
        Schema::table('linehaul_assignments', fn (Blueprint $table) => $table->dropColumn(['offer_version', 'declined_at']));
    }
};
