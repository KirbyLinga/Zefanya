<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('refund_amount_minor')->nullable()->after('refund_reference');
            $table->string('refund_recipient', 255)->nullable()->after('refund_amount_minor');
            $table->date('refund_paid_on')->nullable()->after('refund_recipient');
            $table->string('refund_proof_path', 500)->nullable()->after('refund_paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn(['refund_amount_minor', 'refund_recipient', 'refund_paid_on', 'refund_proof_path']);
        });
    }
};
