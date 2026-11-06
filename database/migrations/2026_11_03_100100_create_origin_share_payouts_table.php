<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origin_share_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('parcel_count');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->string('status', 20)->default('PAID');
            $table->foreignId('paid_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['logistics_provider_id', 'reference']);
        });

        Schema::table('cod_remittances', function (Blueprint $table): void {
            // Set once: a parcel's origin share can only be in one payout.
            $table->foreignId('origin_share_payout_id')->nullable()->after('origin_share_minor')
                ->constrained('origin_share_payouts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('origin_share_payout_id');
        });
        Schema::dropIfExists('origin_share_payouts');
    }
};
