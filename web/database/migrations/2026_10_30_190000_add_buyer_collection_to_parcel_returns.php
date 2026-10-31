<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            // The rider sent to fetch a buyer's return. Failed deliveries never leave the network, so stay null.
            $table->foreignId('collection_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->timestamp('collection_assigned_at')->nullable();
            $table->timestamp('collected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('collection_courier_id');
            $table->dropColumn(['collection_assigned_at', 'collected_at']);
        });
    }
};
