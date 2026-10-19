<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('delivery_courier_id')
                ->nullable()
                ->after('courier_id')
                ->constrained('couriers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeign(['delivery_courier_id']);
            $table->dropColumn('delivery_courier_id');
        });
    }
};
