<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_loss_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignId('parcel_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('admins')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('liability_basis', 30);
            $table->unsignedBigInteger('merchandise_minor');
            $table->string('compensation_status', 24);
            $table->string('compensation_reference', 120)->nullable();
            $table->date('compensation_paid_at')->nullable();
            $table->foreignId('recovered_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->timestamp('recovered_at')->nullable();
            $table->string('disposition', 36)->nullable();
            $table->timestamps();
        });
        Schema::create('parcel_loss_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_loss_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Confirmed loss and compensation audit history must be retained.');
    }
};
