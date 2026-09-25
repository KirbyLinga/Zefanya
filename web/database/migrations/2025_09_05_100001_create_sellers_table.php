<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->enum('sex', ['male', 'female']);

            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_no', 11);
            $table->date('birthday');
            $table->unsignedTinyInteger('age');

            // Address: identical pattern to buyers — one logical address,
            // two possible entry modes.
            $table->enum('address_mode', ['api', 'manual'])->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();

            $table->string('business_name');
            $table->foreignId('line_of_business_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            // Two private-disk uploads, stored separately from buyer IDs.
            $table->string('upload_id_path');
            $table->string('business_permit_path');

            // Email verification (same OTP pattern as buyers).
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->enum('status', [
                'pending_verification', // just registered, email not confirmed
                'pending_approval',     // email confirmed, waiting on admin
                'approved',
                'rejected',
            ])->default('pending_verification');

            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};