<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admins - Platform staff who approve accounts, products and money movements.
 *
 * Belongs to: nothing (a starting table).
 * Used by: account_action_histories, admin_document_access_logs, admin_product_restrictions,
 *   allocation_policy_approvals, buyers, cod_remittances, cod_restriction_lifts, incident_events,
 *   logistics_provider_links, logistics_providers, logistics_remittances, logistics_return_charges,
 *   origin_share_payouts, parcel_handoff_exceptions, parcel_returns, platform_refund_expenses,
 *   platform_setting_histories, product_approval_events, product_revisions, products,
 *   seller_payout_requests, seller_settlements, sellers
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('admins')) {
            return;
        }

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('role')->default('admin')->comment('admin, super_admin, moderator, support');
            $table->rememberToken();
            $table->timestamps();

            $table->unique('email');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('admins');
    }
};
