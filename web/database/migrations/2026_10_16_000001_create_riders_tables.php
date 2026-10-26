<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->date('birthday');
            $table->string('email')->unique();
            $table->string('contact_no', 11)->unique();
            $table->string('license_no')->unique();
            $table->date('license_expiry');
            $table->string('vehicle_type', 20);
            $table->string('plate_number')->unique();
            $table->string('drivers_license_path');
            $table->string('or_cr_path');
            $table->string('status')->default('pending_activation');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('rider_activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('riders')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_activation_tokens');
        Schema::dropIfExists('riders');
    }
};
