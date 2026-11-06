<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_sales', function (Blueprint $table) {
            $table->unsignedInteger('flash_quantity')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('store_sales', function (Blueprint $table) {
            $table->dropColumn('flash_quantity');
        });
    }
};
