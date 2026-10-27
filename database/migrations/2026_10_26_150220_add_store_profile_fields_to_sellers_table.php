<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->text('store_description')->nullable();
            $table->string('store_logo_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('region_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropColumn(['store_description', 'store_logo_path', 'contact_email', 'region_code']);
        });
    }
};
