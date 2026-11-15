<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some upgrades already have the two columns from the earlier checkpoint.
        if (Schema::hasColumns('checkout_tokens', ['order_id', 'reconciled_rejected_at'])) {
            return;
        }

        Schema::table('checkout_tokens', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reconciled_rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('checkout_tokens')->whereNotNull('order_id')->orWhereNotNull('reconciled_rejected_at')->exists()) {
            throw new RuntimeException('Cannot remove recorded checkout recovery evidence.');
        }

        Schema::table('checkout_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('reconciled_rejected_at');
        });
    }
};
