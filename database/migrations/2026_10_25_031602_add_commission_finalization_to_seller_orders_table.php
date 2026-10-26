<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->unsignedBigInteger('commission_minor')->nullable();
            $table->unsignedBigInteger('seller_net_minor')->nullable();
            $table->timestamp('commission_finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['commission_bps', 'commission_minor', 'seller_net_minor', 'commission_finalized_at']);
        });
    }
};
