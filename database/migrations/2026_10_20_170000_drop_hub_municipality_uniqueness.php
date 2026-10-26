<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physical hub location is independent from delivery coverage. A company
     * may operate several Station Hubs in the same municipality; coverage
     * uniqueness is enforced on `areas` instead.
     */
    public function up(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->dropUnique('hubs_provider_type_municipality_unique');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->unique(
                ['logistics_provider_id', 'hub_type', 'municipality'],
                'hubs_provider_type_municipality_unique'
            );
        });
    }
};
