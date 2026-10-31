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
        foreach (['sellers', 'logistics_providers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('tin', 15)->nullable()->unique();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['sellers', 'logistics_providers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['tin']);
                $table->dropColumn('tin');
            });
        }
    }
};
