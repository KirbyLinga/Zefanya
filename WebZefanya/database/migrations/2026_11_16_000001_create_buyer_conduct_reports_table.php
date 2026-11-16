<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_conduct_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            // Historical actor IDs; do not prevent the existing account-deletion workflow.
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('buyer_id')->index();
            $table->string('reason', 60);
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open')->index();
            // NULL after resolution: MySQL permits multiple resolved cases but one open case.
            $table->unsignedTinyInteger('unresolved_key')->nullable()->default(1);
            $table->unique(['seller_order_id', 'unresolved_key'], 'buyer_report_unresolved_unique');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Report history must be retained. Roll back application files, not case records.
        throw new LogicException('Buyer conduct report history must not be dropped.');
    }
};
