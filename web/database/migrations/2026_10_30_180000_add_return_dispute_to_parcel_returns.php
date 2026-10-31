<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->timestamp('escalated_at')->nullable()->after('seller_decided_at');
            $table->string('dispute_outcome', 20)->nullable()->after('escalated_at');
            $table->string('dispute_note', 1000)->nullable()->after('dispute_outcome');
            $table->timestamp('dispute_resolved_at')->nullable()->after('dispute_note');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn(['escalated_at', 'dispute_outcome', 'dispute_note', 'dispute_resolved_at']);
        });
    }
};
