<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->string('seller_type', 30)->nullable();
            $table->string('sec_certificate_path')->nullable();
        });
        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->string('sec_number', 11)->nullable()->unique();
            $table->char('company_name_key', 64)->nullable()->unique();
            $table->json('service_areas')->nullable();
            $table->string('region_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sellers', fn (Blueprint $table) => $table->dropColumn(['seller_type', 'sec_certificate_path']));
        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->dropUnique(['sec_number']);
            $table->dropUnique(['company_name_key']);
            $table->dropColumn(['sec_number', 'company_name_key', 'service_areas', 'region_code']);
        });
    }
};
