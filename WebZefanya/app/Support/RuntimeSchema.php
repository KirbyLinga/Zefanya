<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Cached runtime schema probes.
 *
 * The live site is served from this working tree, so code that touches a
 * table/column created by a NOT-YET-RUN migration must degrade gracefully.
 * These helpers answer "does it exist yet?" with a per-request memo, so a
 * page render costs at most one probe per unique table (and none after the
 * migration has run and the answer is stable within the request).
 */
final class RuntimeSchema
{
    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::$columns[$table.'.'.$column] ??= self::hasTable($table)
            && Schema::hasColumn($table, $column);
    }
}
