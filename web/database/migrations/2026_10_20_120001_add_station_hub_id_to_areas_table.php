<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            $table->foreignId('station_hub_id')->nullable()->after('logistics_provider_id')
                ->constrained('logistics_hubs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('station_hub_id');
        });
    }
};
