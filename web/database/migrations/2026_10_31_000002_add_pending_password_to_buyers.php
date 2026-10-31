<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            // Already hashed. `password` stays the login until the code is confirmed.
            $table->string('pending_password')->nullable();
            // Stored hashed, same as email_verification_code.
            $table->string('pending_password_code')->nullable();
            $table->dateTime('pending_password_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            $table->dropColumn(['pending_password', 'pending_password_code', 'pending_password_expires_at']);
        });
    }
};
