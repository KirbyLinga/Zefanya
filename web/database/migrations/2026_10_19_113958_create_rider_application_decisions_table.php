<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_application_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_application_decisions');
    }
};
