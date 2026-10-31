<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->timestamp('refunded_at')->nullable()->after('dispute_resolved_at');
            $table->string('refund_reference', 255)->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn(['refunded_at', 'refund_reference']);
        });
    }
};
