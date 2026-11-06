<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money between Zefanya and a Logistics company caused by a return: a transit-damage
        // refund the company repays, or return-trip / failed-delivery pay Zefanya owes it.
        Schema::create('logistics_return_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('parcel_return_id')->constrained('parcel_returns')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('reason', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->string('settlement_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['parcel_return_id', 'reason']);
            $table->index(['logistics_provider_id', 'settled_at']);
        });

        Schema::table('parcel_returns', function (Blueprint $table): void {
            // Why Admin refunded less than the buyer paid.
            $table->string('refund_reason', 1000)->nullable()->after('refund_proof_path');
        });

        // A failed delivery returned to the Seller has no settlement, but still a debt.
        Schema::table('seller_debts', function (Blueprint $table): void {
            $table->foreignId('seller_settlement_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seller_debts', function (Blueprint $table): void {
            $table->foreignId('seller_settlement_id')->nullable(false)->change();
        });
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn('refund_reason');
        });
        Schema::dropIfExists('logistics_return_charges');
    }
};
