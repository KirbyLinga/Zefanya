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
        Schema::table('store_categories', function (Blueprint $table) {
            // Equal positions retain the existing alphabetical order; no membership backfill.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_visible']);
        });
    }
};
