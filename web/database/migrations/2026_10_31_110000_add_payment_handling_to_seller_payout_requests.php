<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_payout_requests', function (Blueprint $table): void {
            // Where the Seller wants the money sent, and how Admin answered.
            $table->string('payout_destination')->nullable();
            $table->string('payout_reference')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        // Shown to Logistics companies on their Earnings page. Blank until Admin fills it in.
        DB::table('platform_settings')->insert([
            'key' => 'remittance_payment_details', 'value' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_setting_histories')->where('setting_key', 'remittance_payment_details')->delete();
        DB::table('platform_settings')->where('key', 'remittance_payment_details')->delete();

        Schema::table('seller_payout_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by_admin_id');
            $table->dropColumn(['payout_destination', 'payout_reference', 'paid_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
