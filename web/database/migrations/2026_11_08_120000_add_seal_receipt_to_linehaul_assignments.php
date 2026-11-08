<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->json('seal_receipt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->dropColumn('seal_receipt');
        });
    }
};
