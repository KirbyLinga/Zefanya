<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            foreach (['cancelled', 'recovered'] as $event) {
                $table->timestamp($event.'_at')->nullable();
                $table->string($event.'_by_type', 20)->nullable();
                $table->unsignedBigInteger($event.'_by_id')->nullable();
            }
            $table->text('cancellation_reason')->nullable();
        });
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->unsignedInteger('collection_failed_attempts')->default(0);
            $table->timestamp('collection_last_failed_at')->nullable();
            $table->timestamp('collection_escalated_at')->nullable();
        });
        Schema::table('manifest_items', function (Blueprint $table): void {
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('recovered_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('recovered_by_type', 20)->nullable();
            $table->unsignedBigInteger('recovered_by_id')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves handoff and return audit history.');
    }
};
