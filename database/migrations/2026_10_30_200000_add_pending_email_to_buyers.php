<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            // The address being verified. `email` stays the login until the code is confirmed.
            $table->string('pending_email')->nullable();
            // Stored hashed, same as email_verification_code.
            $table->string('pending_email_code')->nullable();
            $table->dateTime('pending_email_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            $table->dropColumn(['pending_email', 'pending_email_code', 'pending_email_expires_at']);
        });
    }
};
