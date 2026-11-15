<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sellers - Shop owners, with their business details and store profile.
 *
 * Belongs to:
 *   - admins (approved_by) - optional
 *   - categories (line_of_business_id) - optional
 * Used by: conversations, parcel_returns, pickup_sessions, product_revisions, products, seller_debts,
 *   seller_orders, seller_payout_requests, seller_settlements, seller_shipping_providers,
 *   store_categories, store_follows, store_sales, vouchers
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('sellers')) {
            return;
        }

        Schema::create('sellers', function (Blueprint $table): void {
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
            $table->string('postal_code', 10)->nullable();
            $table->string('business_name');
            $table->foreignId('line_of_business_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('upload_id_path');
            $table->string('business_permit_path');
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected', 'suspended'])->default('unverified');
            $table->timestamp('archived_at')->nullable();
            $table->string('registration_email')->nullable();
            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->text('store_description')->nullable();
            $table->string('store_logo_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('region_code', 20)->nullable();
            $table->char('store_name_key', 64)->nullable();
            $table->string('store_banner_path')->nullable();
            $table->boolean('is_on_vacation')->default(false);
            $table->string('tin', 15)->nullable();
            $table->string('seller_type', 30)->nullable();
            $table->string('sec_certificate_path')->nullable();

            $table->unique('contact_no');
            $table->unique('email');
            $table->unique('google_id');
            $table->unique('store_name_key');
            $table->unique('tin');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        // Recreated when a pending drop migration is rolled back; it points at this table.
        Schema::dropIfExists('seller_promotions');

        Schema::dropIfExists('sellers');
    }
};
