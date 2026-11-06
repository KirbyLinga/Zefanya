<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->unsignedInteger('earning_count');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->string('status', 20)->default('PAID');
            $table->timestamps();

            $table->index(['courier_id', 'paid_on']);
        });

        Schema::table('rider_earnings', function (Blueprint $table): void {
            // Set once: an earning can only be in one payout.
            $table->foreignId('rider_payout_id')->nullable()->after('earned_at')
                ->constrained('rider_payouts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rider_payout_id');
        });
        Schema::dropIfExists('rider_payouts');
    }
};
