<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linehaul_push_registrations', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->text('encrypted_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linehaul_push_registrations');
    }
};
