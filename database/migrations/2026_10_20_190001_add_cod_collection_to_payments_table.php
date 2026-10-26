<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COD is collected by the Rider at the buyer's door. The existing `paid_at`
 * already marks settlement; these columns add the collection audit trail
 * (who collected, how much was handed over, and when) without changing the
 * payment lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('collected_by_type', 20)->nullable()->after('paid_at');
            $table->unsignedBigInteger('collected_by_id')->nullable()->after('collected_by_type');
            $table->unsignedBigInteger('collected_amount_minor')->nullable()->after('collected_by_id');
            $table->timestamp('collected_at')->nullable()->after('collected_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'collected_by_type',
                'collected_by_id',
                'collected_amount_minor',
                'collected_at',
            ]);
        });
    }
};
