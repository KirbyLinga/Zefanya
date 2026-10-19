<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standardize registration status values across Buyer, Seller, and LogisticsProvider:
     * - pending_verification → unverified
     * - pending_approval → pending
     * - Add otp_attempts field to track failed OTP verification attempts
     * - Add admin_remarks to logistics_providers for consistency
     */
    public function up(): void
    {
        // Update buyers table
        // First change the enum to accept both old and new values
        Schema::table('buyers', function (Blueprint $table) {
            $table->enum('status', [
                'pending_verification', 'pending_approval', 'unverified', 'pending', 'approved', 'rejected',
            ])->default('unverified')->change();
        });

        // Update data: pending_verification → unverified, pending_approval → pending
        DB::table('buyers')
            ->where('status', 'pending_verification')
            ->update(['status' => 'unverified']);

        DB::table('buyers')
            ->where('status', 'pending_approval')
            ->update(['status' => 'pending']);

        // Now remove old values and add otp_attempts
        Schema::table('buyers', function (Blueprint $table) {
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected'])
                ->default('unverified')
                ->change();
            if (! Schema::hasColumn('buyers', 'otp_attempts')) {
                $table->unsignedTinyInteger('otp_attempts')->default(0)->after('email_verified_at');
            }
        });

        // Update sellers table
        // First change the enum to accept both old and new values
        Schema::table('sellers', function (Blueprint $table) {
            $table->enum('status', [
                'pending_verification', 'pending_approval', 'unverified', 'pending', 'approved', 'rejected',
            ])->default('unverified')->change();
        });

        // Update data: pending_verification → unverified, pending_approval → pending
        DB::table('sellers')
            ->where('status', 'pending_verification')
            ->update(['status' => 'unverified']);

        DB::table('sellers')
            ->where('status', 'pending_approval')
            ->update(['status' => 'pending']);

        // Now remove old values and add otp_attempts
        Schema::table('sellers', function (Blueprint $table) {
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected'])
                ->default('unverified')
                ->change();
            if (! Schema::hasColumn('sellers', 'otp_attempts')) {
                $table->unsignedTinyInteger('otp_attempts')->default(0)->after('email_verified_at');
            }
        });

        // Update logistics_providers table
        // Update data first: pending_verification → unverified, pending_approval → pending
        DB::table('logistics_providers')
            ->where('status', 'pending_verification')
            ->update(['status' => 'unverified']);

        DB::table('logistics_providers')
            ->where('status', 'pending_approval')
            ->update(['status' => 'pending']);

        // Now change status from VARCHAR to enum and add otp_attempts, admin_remarks
        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->enum('status', ['unverified', 'pending', 'approved', 'rejected'])
                ->default('unverified')
                ->change();
            if (! Schema::hasColumn('logistics_providers', 'otp_attempts')) {
                $table->unsignedTinyInteger('otp_attempts')->default(0)->after('email_verified_at');
            }
            if (! Schema::hasColumn('logistics_providers', 'admin_remarks')) {
                $table->string('admin_remarks', 500)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        // Revert logistics_providers
        DB::table('logistics_providers')
            ->where('status', 'unverified')
            ->update(['status' => 'pending_verification']);

        DB::table('logistics_providers')
            ->where('status', 'pending')
            ->update(['status' => 'pending_approval']);

        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->string('status')->default('pending_approval')->change();
            if (Schema::hasColumn('logistics_providers', 'otp_attempts')) {
                $table->dropColumn('otp_attempts');
            }
            if (Schema::hasColumn('logistics_providers', 'admin_remarks')) {
                $table->dropColumn('admin_remarks');
            }
        });

        // Revert sellers
        DB::table('sellers')
            ->where('status', 'unverified')
            ->update(['status' => 'pending_verification']);

        DB::table('sellers')
            ->where('status', 'pending')
            ->update(['status' => 'pending_approval']);

        Schema::table('sellers', function (Blueprint $table) {
            $table->enum('status', ['pending_verification', 'pending_approval', 'approved', 'rejected'])
                ->default('pending_verification')
                ->change();
            if (Schema::hasColumn('sellers', 'otp_attempts')) {
                $table->dropColumn('otp_attempts');
            }
        });

        // Revert buyers
        DB::table('buyers')
            ->where('status', 'unverified')
            ->update(['status' => 'pending_verification']);

        DB::table('buyers')
            ->where('status', 'pending')
            ->update(['status' => 'pending_approval']);

        Schema::table('buyers', function (Blueprint $table) {
            $table->enum('status', ['pending_verification', 'pending_approval', 'approved', 'rejected'])
                ->default('pending_verification')
                ->change();
            if (Schema::hasColumn('buyers', 'otp_attempts')) {
                $table->dropColumn('otp_attempts');
            }
        });
    }
};
