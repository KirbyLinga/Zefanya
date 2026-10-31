<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            // Set once by the Seller's End action, and final. A plain expiry can still be extended.
            $table->dateTime('ended_at')->nullable()->after('expires_at');
        });

        // The buyer's wallet: one row per voucher they claimed.
        Schema::create('voucher_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['voucher_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_claims');

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn('ended_at');
        });
    }
};
