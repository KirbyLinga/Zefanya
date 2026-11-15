<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_couriers')) {
            Schema::create('transfer_couriers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('logistics_provider_id')->constrained()->restrictOnDelete();
                $table->string('name', 100);
                $table->string('contact_no', 20);
                $table->string('vehicle_type', 40);
                $table->string('plate_number', 20)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->index(['logistics_provider_id', 'status']);
            });
        }

        foreach (['manifests', 'parcel_handoffs'] as $name) {
            $columns = Schema::getColumnListing($name);
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                $definitions = [
                    'transfer_courier_id' => fn () => $table->foreignId('transfer_courier_id')->nullable()->constrained()->restrictOnDelete(),
                    'transport_required' => fn () => $table->boolean('transport_required')->default(false),
                    'transport_vehicle_type' => fn () => $table->string('transport_vehicle_type', 40)->nullable(),
                    'transport_plate_number' => fn () => $table->string('transport_plate_number', 20)->nullable(),
                    'transport_assigned_at' => fn () => $table->timestamp('transport_assigned_at')->nullable(),
                    'transport_assigned_by_id' => fn () => $table->foreignId('transport_assigned_by_id')->nullable()->constrained('logistics_providers')->restrictOnDelete(),
                ];

                foreach ($definitions as $column => $define) {
                    if (! in_array($column, $columns, true)) {
                        $define();
                    }
                }
            });
        }

        if (! Schema::hasTable('transfer_courier_assignments')) {
            Schema::create('transfer_courier_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('transfer_courier_id')->constrained()->restrictOnDelete();
                $table->foreignId('logistics_provider_id')->constrained()->restrictOnDelete();
                $table->foreignId('manifest_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('parcel_handoff_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('vehicle_type', 40);
                $table->string('plate_number', 20)->nullable();
                $table->timestamp('assigned_at');
                $table->timestamp('replaced_at')->nullable();
                $table->timestamps();
                $table->index(['transfer_courier_id', 'logistics_provider_id'], 'transfer_courier_history_owner');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Transfer Courier assignments must be retained. Roll back the application independently.');
    }
};
