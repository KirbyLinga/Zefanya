<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individual courier/rider rows. A courier belongs to a logistics provider
     * (a company — the logistics_providers table) but is tracked separately so
     * the assignment engine can match on service_area + capacity.
     */
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('logistics_provider_id')
                ->constrained('logistics_providers')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('phone', 11);

            // Municipality codes this rider covers, e.g. ["QUEZON_CITY", ...].
            $table->json('service_area');

            $table->boolean('is_available')->default(true);

            // 'bike', 'motorcycle', 'van' — drives a max-weight threshold in
            // CourierAssignmentService. Stored as plain strings (VARCHAR + app cast).
            $table->string('vehicle_type', 20)->default('motorcycle');

            // Capacity ceiling in grams for this courier's vehicle type.
            $table->unsignedInteger('max_weight_grams')->default(5000);

            $table->timestamps();

            $table->index(['is_available', 'vehicle_type']);
            $table->index('logistics_provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
