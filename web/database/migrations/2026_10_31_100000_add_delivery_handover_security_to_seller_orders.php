<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H1: fail-closed delivery handover.
     *
     * Additive only. `delivery_otp_hash` already exists; this adds the expiry,
     * single-use marker and proof-of-delivery path, plus the audit trail used
     * by admin overrides and successful handovers.
     */
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_orders', 'delivery_otp_expires_at')) {
                $table->timestamp('delivery_otp_expires_at')->nullable()->after('delivery_otp_locked_until');
            }

            if (! Schema::hasColumn('seller_orders', 'delivery_otp_used_at')) {
                $table->timestamp('delivery_otp_used_at')->nullable()->after('delivery_otp_expires_at');
            }

            if (! Schema::hasColumn('seller_orders', 'delivery_proof_path')) {
                $table->string('delivery_proof_path', 500)->nullable()->after('delivery_otp_used_at');
            }
        });

        if (! Schema::hasTable('delivery_handover_audits')) {
            Schema::create('delivery_handover_audits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
                $table->string('actor_type', 32);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action', 40);
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['seller_order_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_handover_audits');

        Schema::table('seller_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('seller_orders', 'delivery_proof_path')) {
                $table->dropColumn('delivery_proof_path');
            }
            if (Schema::hasColumn('seller_orders', 'delivery_otp_used_at')) {
                $table->dropColumn('delivery_otp_used_at');
            }
            if (Schema::hasColumn('seller_orders', 'delivery_otp_expires_at')) {
                $table->dropColumn('delivery_otp_expires_at');
            }
        });
    }
};
