<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Explicit activation: migrate --path=database/migrations/pending on an approved deployment.
return new class extends Migration
{
    public function up(): void
    {
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
