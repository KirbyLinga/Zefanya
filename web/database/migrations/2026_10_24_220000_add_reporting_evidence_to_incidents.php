<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('reason')->nullable();
            $table->json('custody_snapshot')->nullable();
            $table->string('photo_disk', 32)->nullable();
            $table->string('photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn(['reason', 'custody_snapshot', 'photo_disk', 'photo_path']);
        });
    }
};
