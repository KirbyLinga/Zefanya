<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.2 adds the decision metadata needed to reject a partnership request
 * and to revoke an Admin approval without deleting the audit trail.
 *
 * Status values live in App\Models\Logistics\LogisticsProviderLink; the
 * existing VARCHAR(20) column accepts REJECTED and REVOKED without a type
 * change. Both decision actors are retained so a link can be reconstructed
 * exactly as it was acted upon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_provider_links', function (Blueprint $table): void {
            // Destination provider decision.
            $table->foreignId('rejected_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            // Admin approval revocation.
            $table->foreignId('revoked_by')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();

            $table->index(['status', 'created_at'], 'provider_links_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_provider_links', function (Blueprint $table): void {
            $table->dropIndex('provider_links_status_created_idx');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason', 'revoked_at', 'revocation_reason']);
        });
    }
};
