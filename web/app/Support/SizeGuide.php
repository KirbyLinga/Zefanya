<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/** Garment length guide (config/size_guide.php) and the products.garment_type column guard. */
class SizeGuide
{
    private static ?bool $columnExists = null;

    /** False until the products.garment_type column exists, so a code deploy never breaks saving. */
    public static function columnExists(): bool
    {
        return self::$columnExists ??= Schema::hasColumn('products', 'garment_type');
    }

    public static function flushColumnCache(): void
    {
        self::$columnExists = null;
    }

    /** @return array<string, array{label: string, types: array<string, array{label: string, cm: string, in: string}>}> */
    public static function groups(): array
    {
        return config('size_guide.groups', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            array_push($keys, ...array_keys($group['types']));
        }

        return $keys;
    }

    /** @return array{group: string, label: string, cm: string, in: string, group_key: string}|null */
    public static function find(?string $key): ?array
    {
        foreach (self::groups() as $groupKey => $group) {
            if (isset($group['types'][$key])) {
                return $group['types'][$key] + ['group' => $group['label'], 'group_key' => $groupKey];
            }
        }

        return null;
    }

    public static function appliesToCategoryLabel(?string $label): bool
    {
        return $label !== null && in_array($label, config('size_guide.categories', []), true);
    }
}
