<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logistics provider applications/accounts.
     *
     * `status` is a VARCHAR, never a native MySQL ENUM — the permitted values
     * live in App\Enums\LogisticsProviderStatus and are cast by the model
     * (project rule #6).
     */
    public function up(): void
    {
        Schema::create('logistics_providers', function (Blueprint $table) {
            $table->id();

            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->string('sex', 10);

            // Unique inside this table only; cross-role reuse (buyer/seller) is
            // rejected in StoreLogisticsRegistrationRequest, not by the schema.
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_no', 11);
            $table->date('birthday');
            $table->unsignedTinyInteger('age');

            $table->string('business_name');

            // Address: identical pattern to buyers/sellers — one logical address,
            // two possible entry modes.
            $table->string('address_mode', 10)->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();

            // Two private-disk uploads, stored separately from buyer/seller files.
            $table->string('upload_id_path');
            $table->string('dti_permit_path');

            // Email verification (same OTP pattern as sellers; the stored code is
            // a SHA-256 hash, never the raw one-time code).
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->string('status')->default('pending_approval');

            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_providers');
    }
};
