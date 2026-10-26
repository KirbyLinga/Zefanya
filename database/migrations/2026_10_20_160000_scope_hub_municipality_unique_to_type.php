<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Province Hub stores the municipality of its own office, which must not
     * reserve that municipality for Station Hubs. Scope the uniqueness to
     * (provider, hub_type, municipality) so a provider can still only have one
     * Station Hub per municipality.
     */
    public function up(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->dropUnique(['logistics_provider_id', 'municipality']);
            $table->unique(
                ['logistics_provider_id', 'hub_type', 'municipality'],
                'hubs_provider_type_municipality_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->dropUnique('hubs_provider_type_municipality_unique');
            $table->unique(['logistics_provider_id', 'municipality']);
        });
    }
};
