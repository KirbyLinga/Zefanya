<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Municipality coverage now uses the PSGC city/municipality code, so two
     * same-named municipalities in different provinces no longer collide.
     * Legacy rows keep a null code until they are reassigned.
     *
     * The replacement index is created before the old one is dropped because
     * MySQL uses the old unique index to support the provider foreign key.
     * The checks make the migration safe to rerun after a partial DDL failure.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('areas', 'city_municipality_code')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->string('city_municipality_code', 20)->nullable()->after('municipality');
            });
        }

        if (! $this->hasIndex('areas_provider_city_unique')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->unique(
                    ['logistics_provider_id', 'city_municipality_code'],
                    'areas_provider_city_unique'
                );
            });
        }

        if ($this->hasIndex('areas_logistics_provider_id_municipality_unique')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->dropUnique('areas_logistics_provider_id_municipality_unique');
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex('areas_logistics_provider_id_municipality_unique')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->unique(['logistics_provider_id', 'municipality']);
            });
        }

        if ($this->hasIndex('areas_provider_city_unique')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->dropUnique('areas_provider_city_unique');
            });
        }

        if (Schema::hasColumn('areas', 'city_municipality_code')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->dropColumn('city_municipality_code');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('areas'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
