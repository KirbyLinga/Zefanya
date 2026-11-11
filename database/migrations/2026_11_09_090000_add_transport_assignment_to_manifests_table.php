<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who carries a hub-to-hub transfer batch. The sending company assigns one of
 * its own Riders with a vehicle and optional plate. Only batches created after
 * this migration require it (transport_required); historical batches keep
 * dispatching as they always did. Additive columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->boolean('transport_required')->default(false)->after('direction');
            $table->foreignId('transport_courier_id')->nullable()->after('transport_required')->constrained('couriers')->restrictOnDelete();
            $table->string('transport_vehicle_type', 20)->nullable()->after('transport_courier_id');
            $table->string('transport_plate_number', 20)->nullable()->after('transport_vehicle_type');
            $table->timestamp('transport_assigned_at')->nullable()->after('transport_plate_number');
            $table->unsignedBigInteger('transport_assigned_by_id')->nullable()->after('transport_assigned_at');

            $table->index(['transport_courier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->dropForeign(['transport_courier_id']);
            $table->dropIndex(['transport_courier_id', 'status']);
            $table->dropColumn(['transport_courier_id', 'transport_required', 'transport_vehicle_type', 'transport_plate_number', 'transport_assigned_at', 'transport_assigned_by_id']);
        });
    }
};
