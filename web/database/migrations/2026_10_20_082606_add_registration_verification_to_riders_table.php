<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_code', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['email_verified_at', 'email_verification_code', 'email_verification_expires_at', 'otp_attempts', 'decided_at', 'rejection_reason']);
        });
    }
};
