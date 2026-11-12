<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_audit_logs', function (Blueprint $table): void {
            $table->string('action', 40)->change();
        });
        Schema::table('linehaul_trip_payouts', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->default(1);
            $table->unique(['linehaul_assignment_id', 'sequence'], 'linehaul_payout_assignment_sequence');
        });
        Schema::table('linehaul_trip_payouts', function (Blueprint $table): void {
            $table->dropUnique(['linehaul_assignment_id']);
        });
        Schema::create('linehaul_trip_payout_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_trip_payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('reversed_payout_id')->nullable()->unique()->constrained('linehaul_trip_payouts')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained()->restrictOnDelete();
            $table->string('staff_name', 120);
            $table->string('previous_reference', 100);
            $table->string('reference', 100);
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('linehaul_trip_payout_corrections')->exists()) {
            throw new RuntimeException('Courier payout corrections exist and must be retained.');
        }
        Schema::dropIfExists('linehaul_trip_payout_corrections');
        Schema::table('linehaul_trip_payouts', function (Blueprint $table): void {
            $table->unique('linehaul_assignment_id');
        });
        // Keep the wider audit column so existing manifest history remains readable.
        Schema::table('linehaul_trip_payouts', function (Blueprint $table): void {
            $table->dropUnique('linehaul_payout_assignment_sequence');
            $table->dropColumn('sequence');
        });
    }
};
