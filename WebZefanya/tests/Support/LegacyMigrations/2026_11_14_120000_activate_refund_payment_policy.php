<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Archived: this used to live in database/migrations/pending and was run by hand. Its column is now part of
// create_parcel_returns_table. Kept because the refund-policy tests run it to switch the policy on and off.
return new class extends Migration
{
    public function up(): void
    {
        // A fresh install already gets this column from create_parcel_returns_table.
        if (Schema::hasColumn('parcel_returns', 'refund_policy_version')) {
            return;
        }

        Schema::table('parcel_returns', function (Blueprint $table): void {
            // Null means historical recording, never retrospectively verified.
            $table->unsignedTinyInteger('refund_policy_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', fn (Blueprint $table) => $table->dropColumn('refund_policy_version'));
    }
};
