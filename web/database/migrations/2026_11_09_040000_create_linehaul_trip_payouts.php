<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linehaul_trip_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_assignment_id')->unique()->constrained('linehaul_assignments')->restrictOnDelete();
            $table->foreignId('linehaul_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linehaul_trip_payouts');
    }
};
