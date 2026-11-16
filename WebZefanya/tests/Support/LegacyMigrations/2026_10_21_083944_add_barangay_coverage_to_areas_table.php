<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits each DDL statement independently. Resume a partially
        // applied upgrade without dropping data or replacing existing columns.
        foreach (['barangay_code', 'barangay_name', 'coverage_validated_at', 'active_barangay_code'] as $column) {
            if (Schema::hasColumn('areas', $column)) {
                continue;
            }
            Schema::table('areas', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'barangay_code' => $table->string($column, 20)->nullable(),
                    'barangay_name' => $table->string($column, 120)->nullable(),
                    'coverage_validated_at' => $table->timestamp($column)->nullable(),
                    'active_barangay_code' => $table->string($column, 20)->nullable()
                        ->virtualAs('CASE WHEN is_active = 1 THEN barangay_code ELSE NULL END'),
                };
            });
        }
        if (DB::getDriverName() === 'mysql') {
            $column = DB::selectOne("SELECT GENERATION_EXPRESSION AS expression FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'areas' AND COLUMN_NAME = 'active_barangay_code'");
            $expression = preg_replace('/[`\\s()]/', '', strtolower($column->expression ?? ''));
            if ($expression !== 'casewhenis_active=1thenbarangay_codeelsenullend') {
                throw new RuntimeException('Existing active_barangay_code has an unexpected definition. Review the partial migration; no existing column will be overwritten.');
            }
        }
        $indexes = collect(Schema::getIndexes('areas'));
        $claimIndex = $indexes->firstWhere('name', 'areas_provider_barangay_unique');
        if ($claimIndex !== null && (! $claimIndex['unique'] || $claimIndex['columns'] !== ['logistics_provider_id', 'active_barangay_code'])) {
            throw new RuntimeException('Existing barangay claim index has an unexpected definition. Review it before resuming.');
        }
        // Keep an ordinary FK-supporting index independent of generated indexes.
        if (! $indexes->contains('name', 'areas_provider_coverage_index')) {
            Schema::table('areas', fn (Blueprint $table) => $table->index('logistics_provider_id', 'areas_provider_coverage_index'));
        }
        if (! $indexes->contains('name', 'areas_provider_barangay_unique')) {
            Schema::table('areas', fn (Blueprint $table) => $table->unique(['logistics_provider_id', 'active_barangay_code'], 'areas_provider_barangay_unique'));
        }
        if ($indexes->contains('name', 'areas_provider_city_unique')) {
            Schema::table('areas', fn (Blueprint $table) => $table->dropUnique('areas_provider_city_unique'));
        }
        // Existing municipality rows and physical hub addresses remain untouched.
    }

    public function down(): void
    {
        // Restoring municipality uniqueness can be impossible after legitimate
        // barangay claims. Refuse before DDL rather than delete or merge records.
        if (DB::table('areas')->whereNotNull('city_municipality_code')
            ->groupBy('logistics_provider_id', 'city_municipality_code')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back: multiple barangay areas share a municipality. Export/reconcile coverage first.');
        }
        Schema::table('areas', function (Blueprint $table): void {
            $table->unique(['logistics_provider_id', 'city_municipality_code'], 'areas_provider_city_unique');
            $table->dropUnique('areas_provider_barangay_unique');
            $table->dropColumn('active_barangay_code');
        });
        Schema::table('areas', function (Blueprint $table): void {
            $table->dropColumn(['barangay_code', 'barangay_name', 'coverage_validated_at']);
        });
    }
};
