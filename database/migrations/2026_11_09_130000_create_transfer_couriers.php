<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Couriers: transport personnel records a Logistics company keeps for moving
 * parcels between its stations, hubs and partner companies. Not user
 * accounts (no login, token or registration) and never paid through Zefanya.
 *
 * - transfer_couriers: the company's Courier list.
 * - transfer_courier_assignments: who carried which batch or handoff, kept as
 *   history when an assignment is changed.
 * - manifests / parcel_handoffs: the current Courier of a transfer.
 *
 * Also undoes the earlier Rider-as-carrier design, none of which holds data:
 * manifests.transport_courier_id (a Rider), the Rider trip timestamps, and the
 * transfer-trip rider pay (rider_earnings.manifest_id and its rate setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_couriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('contact_no', 20);
            $table->string('vehicle_type', 20);
            $table->string('plate_number', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['logistics_provider_id', 'status']);
        });

        if (Schema::hasColumn('manifests', 'transport_courier_id')) {
            Schema::table('manifests', function (Blueprint $table): void {
                $table->dropForeign(['transport_courier_id']);
                $table->dropIndex(['transport_courier_id', 'status']);
                $table->dropColumn('transport_courier_id');
            });
        }
        foreach (['trip_started_at', 'trip_arrived_at'] as $column) {
            if (Schema::hasColumn('manifests', $column)) {
                Schema::table('manifests', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        Schema::table('manifests', function (Blueprint $table): void {
            $table->foreignId('transfer_courier_id')->nullable()->after('transport_required')->constrained('transfer_couriers')->restrictOnDelete();
        });

        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            $table->boolean('transport_required')->default(false)->after('direction');
            $table->foreignId('transfer_courier_id')->nullable()->after('transport_required')->constrained('transfer_couriers')->restrictOnDelete();
            $table->string('transport_vehicle_type', 20)->nullable()->after('transfer_courier_id');
            $table->string('transport_plate_number', 20)->nullable()->after('transport_vehicle_type');
            $table->timestamp('transport_assigned_at')->nullable()->after('transport_plate_number');
            $table->unsignedBigInteger('transport_assigned_by_id')->nullable()->after('transport_assigned_at');
        });

        Schema::create('transfer_courier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_courier_id')->constrained('transfer_couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('manifest_id')->nullable()->constrained('manifests')->cascadeOnDelete();
            $table->foreignId('parcel_handoff_id')->nullable()->constrained('parcel_handoffs')->restrictOnDelete();
            $table->string('vehicle_type', 20);
            $table->string('plate_number', 20)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['transfer_courier_id', 'assigned_at'], 'tc_assignments_courier_assigned_idx');
        });

        // Transfer-trip rider pay is not part of the design (Riders do not carry transfers).
        if (Schema::hasColumn('rider_earnings', 'manifest_id')) {
            Schema::table('rider_earnings', function (Blueprint $table): void {
                $table->dropForeign(['manifest_id']);
                $table->dropUnique(['manifest_id', 'job']);
                $table->dropColumn('manifest_id');
            });
            Schema::table('rider_earnings', function (Blueprint $table): void {
                $table->unsignedBigInteger('seller_order_id')->nullable(false)->change();
            });
        }
        DB::table('platform_settings')->where('key', 'rider_transfer_trip_rate')->where('value', '')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_courier_assignments');
        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_courier_id');
            $table->dropColumn(['transport_required', 'transport_vehicle_type', 'transport_plate_number', 'transport_assigned_at', 'transport_assigned_by_id']);
        });
        Schema::table('manifests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_courier_id');
        });
        Schema::table('manifests', function (Blueprint $table): void {
            $table->foreignId('transport_courier_id')->nullable()->after('transport_required')->constrained('couriers')->restrictOnDelete();
            $table->index(['transport_courier_id', 'status']);
        });
        Schema::dropIfExists('transfer_couriers');
        // The removed Rider trip and trip-pay columns are not restored.
    }
};
