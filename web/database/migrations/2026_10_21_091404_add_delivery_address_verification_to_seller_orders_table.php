<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seller_orders', 'delivery_address_verification')) {
            Schema::table('seller_orders', fn (Blueprint $table) => $table->json('delivery_address_verification')->nullable());
        }
    }

    public function down(): void
    {
        if (DB::table('seller_orders')->whereNotNull('delivery_address_verification')->exists()) {
            throw new RuntimeException('Export verified address evidence before rolling back this migration.');
        }
        Schema::table('seller_orders', fn (Blueprint $table) => $table->dropColumn('delivery_address_verification'));
    }
};
