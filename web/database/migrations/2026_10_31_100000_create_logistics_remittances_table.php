<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One payment from a Logistics company to Zefanya, covering many parcels.
        Schema::create('logistics_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('parcel_count');
            $table->unsignedBigInteger('cod_total_minor');
            $table->unsignedBigInteger('shipping_total_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->string('status', 20)->default('SUBMITTED');
            $table->unsignedBigInteger('received_amount_minor')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['logistics_provider_id', 'id']);
        });

        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->foreignId('logistics_remittance_id')->nullable()->constrained('logistics_remittances')->restrictOnDelete();
            // Set only when Zefanya confirms the money arrived. Seller payouts wait for this.
            $table->timestamp('zefanya_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logistics_remittance_id');
            $table->dropColumn('zefanya_received_at');
        });

        Schema::dropIfExists('logistics_remittances');
    }
};
