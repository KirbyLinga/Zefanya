<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->string('status', 32)->default('DRAFT')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('manifests')->where('status', 'RECEIVED_WITH_EXCEPTIONS')->exists()) {
            throw new RuntimeException('Cannot narrow manifest status while exception receipts exist.');
        }
        Schema::table('manifests', function (Blueprint $table): void {
            $table->string('status', 20)->default('DRAFT')->change();
        });
    }
};
