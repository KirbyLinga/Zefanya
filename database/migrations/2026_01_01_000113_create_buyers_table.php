<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * buyers - Customers who place orders.
 *
 * Belongs to:
 *   - admins (approved_by) - optional
 * Used by: buyer_addresses, cart_items, checkout_tokens, cod_delivery_strikes, cod_restriction_lifts,
 *   conversations, orders, reviews, store_follows, voucher_claims, wishlists
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('buyers')) {
            return;
        }

        Schema::create('buyers', function (Blueprint $table): void {
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
            $table->string('upload_id_path');
            $table->string('email_verification_code')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->json('settings')->nullable();
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected', 'suspended'])->default('unverified');
            $table->timestamp('archived_at')->nullable();
            $table->string('registration_email')->nullable();
            $table->string('admin_remarks', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_code')->nullable();
            $table->dateTime('pending_email_expires_at')->nullable();
            $table->string('pending_password')->nullable();
            $table->string('pending_password_code')->nullable();
            $table->dateTime('pending_password_expires_at')->nullable();

            $table->unique('contact_no');
            $table->unique('email');
            $table->unique('google_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('buyers');
    }
};
