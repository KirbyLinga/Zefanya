<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('linehaul_drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('home_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 20)->default('inactive');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('linehaul_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('manifests')->cascadeOnDelete();
            $table->foreignId('linehaul_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->string('seal_number', 10)->nullable();
            $table->longText('loading_tokens')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('linehaul_assignments');
        Schema::dropIfExists('linehaul_drivers');
    }
};
