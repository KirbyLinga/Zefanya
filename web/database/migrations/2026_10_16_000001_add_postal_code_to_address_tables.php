<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postal code is one of the few address fields the ERP allows to be typed by
 * hand (province / municipality / barangay are dropdown-only). It was never
 * captured on any role's address, so orders, labels and receipts had no postal
 * code to print. Added to every table that stores a registrable address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            $table->string('postal_code', 10)->nullable()->after('address_detail');
        });

        Schema::table('sellers', function (Blueprint $table): void {
            $table->string('postal_code', 10)->nullable()->after('address_detail');
        });

        Schema::table('logistics_providers', function (Blueprint $table): void {
            $table->string('postal_code', 10)->nullable()->after('address_detail');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            $table->dropColumn('postal_code');
        });

        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropColumn('postal_code');
        });

        Schema::table('logistics_providers', function (Blueprint $table): void {
            $table->dropColumn('postal_code');
        });
    }
};
