<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_hubs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('parent_hub_id')->nullable();
            $table->string('name', 120);
            $table->string('hub_type', 20);
            $table->string('province_name', 120);
            $table->string('municipality', 120)->nullable();
            $table->string('location_details', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'logistics_provider_id'], 'hubs_id_provider_unique');
            $table->foreign(['parent_hub_id', 'logistics_provider_id'], 'hubs_parent_provider_fk')
                ->references(['id', 'logistics_provider_id'])
                ->on('logistics_hubs')
                ->restrictOnDelete();
            $table->index(['logistics_provider_id', 'hub_type', 'is_active']);
            $table->unique(['logistics_provider_id', 'municipality']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_hubs');
    }
};
