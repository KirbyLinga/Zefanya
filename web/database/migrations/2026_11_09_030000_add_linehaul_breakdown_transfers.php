<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('linehaul_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_breakdown_id')->unique()->constrained('linehaul_breakdown_reports')->restrictOnDelete();
            $table->foreignId('linehaul_assignment_id')->constrained('linehaul_assignments')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('manifests')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('from_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->foreignId('to_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->unsignedInteger('offer_version');
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['linehaul_assignment_id', 'offer_version']);
        });
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->dateTime('takeover_resumed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->dropColumn('takeover_resumed_at');
        });
        Schema::dropIfExists('linehaul_transfers');
    }
};
