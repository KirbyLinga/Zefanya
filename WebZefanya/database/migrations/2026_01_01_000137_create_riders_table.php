<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * riders - Individual drivers who work for a delivery company.
 *
 * Belongs to:
 *   - logistics_providers (decided_by) - optional
 *   - logistics_providers (logistics_provider_id)
 *   - logistics_hubs (station_hub_id) - optional
 * Used by: couriers, delivery_proofs, pickup_attempts, rider_activation_tokens
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('riders')) {
            return;
        }

        Schema::create('riders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('station_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->boolean('is_online')->default(true);
            $table->timestamp('online_changed_at')->nullable();
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->date('birthday');
            $table->string('email');
            $table->string('contact_no', 11);
            $table->string('address_mode', 20)->nullable();
            $table->string('province_code', 20)->nullable();
            $table->string('province_name', 120)->nullable();
            $table->string('municipality_code', 20)->nullable();
            $table->string('municipality_name', 120)->nullable();
            $table->string('barangay_code', 20)->nullable();
            $table->string('barangay_name', 120)->nullable();
            $table->string('street')->nullable();
            $table->string('house_number', 50)->nullable();
            $table->string('address_detail')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('license_no');
            $table->date('license_expiry');
            $table->string('vehicle_type', 20);
            $table->string('plate_number');
            $table->string('drivers_license_path');
            $table->string('or_cr_path');
            $table->string('status')->default('pending_activation');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_code', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            $table->unique('contact_no');
            $table->unique('email');
            $table->unique('license_no');
            $table->unique('plate_number');
            $table->index(['logistics_provider_id', 'status'], 'riders_provider_status_idx');
            $table->index(['station_hub_id', 'status'], 'riders_station_status_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('riders');
    }
};
