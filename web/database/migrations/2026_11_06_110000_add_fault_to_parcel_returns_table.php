<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            // Who pays for the return: 'seller' or 'logistics'. Null until the return is approved.
            $table->string('fault_party', 20)->nullable()->after('refund_proof_path');
            $table->string('fault_note', 1000)->nullable()->after('fault_party');
            $table->foreignId('fault_set_by_admin_id')->nullable()->after('fault_note')->constrained('admins')->nullOnDelete();
            $table->timestamp('fault_set_at')->nullable()->after('fault_set_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fault_set_by_admin_id');
            $table->dropColumn(['fault_party', 'fault_note', 'fault_set_at']);
        });
    }
};
