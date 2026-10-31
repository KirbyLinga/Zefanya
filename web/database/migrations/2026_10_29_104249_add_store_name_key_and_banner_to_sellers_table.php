<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            // No backfill: legacy duplicate names and existing profiles remain untouched.
            $table->char('store_name_key', 64)->nullable()->unique();
            $table->string('store_banner_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropUnique(['store_name_key']);
            $table->dropColumn(['store_name_key', 'store_banner_path']);
        });
    }
};
