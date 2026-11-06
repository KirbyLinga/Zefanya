<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_remittances', function (Blueprint $table): void {
            // Set when Admin confirmed only some parcels because less money arrived than stated.
            $table->unsignedBigInteger('short_minor')->default(0)->after('received_amount_minor');
            $table->unsignedInteger('left_out_count')->default(0)->after('short_minor');
        });

        Schema::table('cod_remittances', function (Blueprint $table): void {
            // One-time Admin decision on a parcel sent under the old "full fee to Zefanya" rule.
            $table->string('reconciliation', 20)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            // The delivering company's 60% that Zefanya still held when the parcel was allocated.
            $table->unsignedBigInteger('destination_share_minor')->nullable();
            $table->string('destination_paid_reference', 100)->nullable();
            $table->date('destination_paid_on')->nullable();
            $table->foreignId('destination_paid_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reconciled_by_admin_id');
            $table->dropConstrainedForeignId('destination_paid_by_admin_id');
            $table->dropColumn(['reconciliation', 'reconciled_at', 'destination_share_minor', 'destination_paid_reference', 'destination_paid_on']);
        });

        Schema::table('logistics_remittances', function (Blueprint $table): void {
            $table->dropColumn(['short_minor', 'left_out_count']);
        });
    }
};
