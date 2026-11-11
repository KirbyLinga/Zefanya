<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cancelled transfer that had already left its hub does not prove where its
 * parcels are. Each parcel stays under exception until a verified recovery
 * scan at a hub records where it physically is. Additive columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_items', function (Blueprint $table): void {
            $table->timestamp('recovered_at')->nullable()->after('received_at');
            $table->foreignId('recovered_hub_id')->nullable()->after('recovered_at')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('recovered_by_type', 20)->nullable()->after('recovered_hub_id');
            $table->unsignedBigInteger('recovered_by_id')->nullable()->after('recovered_by_type');
        });
    }

    public function down(): void
    {
        Schema::table('manifest_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recovered_hub_id');
            $table->dropColumn(['recovered_at', 'recovered_by_type', 'recovered_by_id']);
        });
    }
};
