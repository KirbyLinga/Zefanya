<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_initial', 2)->nullable();
            $table->string('sex', 10);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('contact_no', 11)->nullable()->unique();
            $table->date('birthday');
            foreach (['province', 'municipality', 'barangay'] as $part) {
                $table->string($part.'_code', 20);
                $table->string($part.'_name');
            }
            $table->string('street');
            $table->string('house_number', 50)->nullable();
            $table->string('address_detail')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('vehicle_type', 20);
            $table->string('plate_number', 20);
            $table->string('upload_id_path');
            $table->string('or_cr_path');
            $table->string('status', 20)->default('unverified');
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->string('registration_email')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['logistics_provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_applications');
    }
};
