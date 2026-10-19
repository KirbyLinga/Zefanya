<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C20 + Stage 1: live contact numbers must be unique per role table; archived
 * rows free the number by nulling contact_no (MySQL unique allows many NULLs).
 * Logistics sex is aligned to the same male|female enum as buyers/sellers.
 *
 * Idempotent: safe to resume after a partial apply (nullable before nulling,
 * unique index only when missing).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            // Nullable first — duplicates/archived rows are nulled next.
            if ($driver === 'sqlite') {
                // SQLite has no MODIFY; Laravel rebuilds the table for ->change().
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('contact_no', 11)->nullable()->change();
                });
            } else {
                DB::statement("ALTER TABLE {$table} MODIFY contact_no VARCHAR(11) NULL");
            }

            if (Schema::hasColumn($table, 'archived_at')) {
                DB::table($table)->whereNotNull('archived_at')->update(['contact_no' => null]);
            }

            $duplicates = DB::table($table)
                ->select('contact_no')
                ->whereNotNull('contact_no')
                ->groupBy('contact_no')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('contact_no');

            foreach ($duplicates as $phone) {
                $keepId = DB::table($table)
                    ->where('contact_no', $phone)
                    ->orderBy('id')
                    ->value('id');

                DB::table($table)
                    ->where('contact_no', $phone)
                    ->where('id', '!=', $keepId)
                    ->update(['contact_no' => null]);
            }

            if (! $this->hasUniqueIndex($table, $table.'_contact_no_unique')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->unique('contact_no', $table.'_contact_no_unique');
                });
            }
        }

        if ($driver !== 'sqlite') {
            DB::table('logistics_providers')
                ->whereNotIn('sex', ['male', 'female'])
                ->update(['sex' => 'male']);

            DB::statement("ALTER TABLE logistics_providers MODIFY sex ENUM('male', 'female') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE logistics_providers MODIFY sex VARCHAR(10) NOT NULL');
        }

        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            if ($this->hasUniqueIndex($table, $table.'_contact_no_unique')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->dropUnique($table.'_contact_no_unique');
                });
            }

            DB::table($table)->whereNull('contact_no')->update(['contact_no' => '09000000000']);

            if ($driver !== 'sqlite') {
                DB::statement("ALTER TABLE {$table} MODIFY contact_no VARCHAR(11) NOT NULL");
            }
        }
    }

    private function hasUniqueIndex(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]);

        return count($rows) > 0;
    }
};
