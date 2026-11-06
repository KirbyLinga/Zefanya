<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('origin_share_payouts', function (Blueprint $table): void {
            // Active references are serialized and validated under the provider lock.
            // A reversal preserves its original reference for the replacement record.
            $table->index(['logistics_provider_id', 'reference'], 'origin_payout_reference_lookup');
            $table->dropUnique(['logistics_provider_id', 'reference']);
        });
    }

    public function down(): void
    {
        if (DB::table('origin_share_payouts')->select('logistics_provider_id', 'reference')
            ->groupBy('logistics_provider_id', 'reference')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore unique references after a corrected payout. Preserve history and apply a forward migration.');
        }

        Schema::table('origin_share_payouts', function (Blueprint $table): void {
            $table->unique(['logistics_provider_id', 'reference']);
            $table->dropIndex('origin_payout_reference_lookup');
        });
    }
};
