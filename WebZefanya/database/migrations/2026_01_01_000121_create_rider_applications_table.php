<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rider_applications - People applying to become a rider, waiting for the company to approve them.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 * Used by: rider_application_decisions
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('rider_applications')) {
            return;
        }

        Schema::create('rider_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_initial', 2)->nullable();
            $table->string('sex', 10);
            $table->string('email');
            $table->string('password');
            $table->string('contact_no', 11)->nullable();
            $table->date('birthday');
            $table->string('province_code', 20);
            $table->string('province_name');
            $table->string('municipality_code', 20);
            $table->string('municipality_name');
            $table->string('barangay_code', 20);
            $table->string('barangay_name');
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

            $table->unique('contact_no');
            $table->unique('email');
            $table->index(['logistics_provider_id', 'status']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('rider_applications');
    }
};
