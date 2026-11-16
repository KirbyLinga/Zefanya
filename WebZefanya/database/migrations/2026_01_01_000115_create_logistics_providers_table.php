<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_providers - Delivery companies. One account per company.
 *
 * Belongs to:
 *   - admins (approved_by) - optional
 *   - admins (province_verified_by) - optional
 * Used by: areas, cod_remittances, couriers, logistics_hubs, logistics_provider_links,
 *   logistics_remittances, logistics_return_charges, logistics_shipping_rates, manifests,
 *   origin_share_payouts, parcel_handoff_exceptions, parcel_handoffs, parcel_loss_cases,
 *   parcel_provider_allocations, parcel_returns, pickup_sessions, rider_application_decisions,
 *   rider_applications, rider_earnings, rider_payouts, riders, seller_orders,
 *   seller_shipping_providers, transfer_courier_assignments, transfer_couriers,
 *   transport_connections
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_providers')) {
            return;
        }

        Schema::create('logistics_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_initial', 2)->nullable();
            $table->enum('sex', ['male', 'female']);
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('google_id')->nullable();
            $table->rememberToken();
            $table->string('contact_no', 11)->nullable();
            $table->date('birthday');
            $table->unsignedTinyInteger('age');
            $table->string('business_name');
            $table->string('address_mode', 10)->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->timestamp('province_verified_at')->nullable();
            $table->foreignId('province_verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('upload_id_path');
            $table->string('dti_permit_path');
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected', 'suspended'])->default('unverified');
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('rider_wind_down_until')->nullable();
            $table->string('registration_email')->nullable();
            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->string('tin', 15)->nullable();
            $table->string('sec_number', 11)->nullable();
            $table->char('company_name_key', 64)->nullable();
            $table->json('service_areas')->nullable();
            $table->string('region_code', 20)->nullable();

            $table->unique('company_name_key');
            $table->unique('contact_no');
            $table->unique('email');
            $table->unique('google_id');
            $table->unique('sec_number');
            $table->unique('tin');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_providers');
    }
};
