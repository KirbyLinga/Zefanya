<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One email address must belong to exactly ONE role across the account
 * tables: buyers, sellers, logistics_providers, riders and admins.
 *
 * Registration requests use this to reject an address that already belongs to
 * another role, and the unified login uses it to refuse an address that is
 * linked to more than one role (which would be ambiguous to sign in to).
 *
 * Archived registration rows (see HasRegistrationArchive) carry a tombstone
 * email, so they are naturally excluded.
 */
class EmailRegistry
{
    /**
     * Role key → table + human label.
     *
     * @var array<string, array{table: string, label: string}>
     */
    private const SOURCES = [
        'buyer' => ['table' => 'buyers', 'label' => 'buyer'],
        'seller' => ['table' => 'sellers', 'label' => 'seller'],
        'logistics' => ['table' => 'logistics_providers', 'label' => 'logistics provider'],
        'admin' => ['table' => 'admins', 'label' => 'administrator'],
        'rider' => ['table' => 'riders', 'label' => 'rider'],
    ];

    /**
     * Every role that owns this email (case-insensitive).
     *
     * @return list<string>
     */
    public static function rolesFor(string $email): array
    {
        $needle = strtolower(trim($email));

        if ($needle === '') {
            return [];
        }

        $roles = [];

        foreach (self::SOURCES as $role => $source) {
            $query = DB::table($source['table'])->whereRaw('LOWER(email) = ?', [$needle]);

            // Role tables archive leftover applicants; admins never archive.
            if (! in_array($role, ['admin', 'rider'], true)) {
                $query->whereNull('archived_at');
            }

            if ($query->exists()) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Every role EXCEPT $ownRole that owns this email.
     *
     * @return list<string>
     */
    public static function otherRolesFor(string $email, string $ownRole): array
    {
        return array_values(array_diff(self::rolesFor($email), [$ownRole]));
    }

    public static function label(string $role): string
    {
        return self::SOURCES[$role]['label'] ?? $role;
    }

    /**
     * Human list of roles, e.g. "seller and buyer".
     *
     * @param  list<string>  $roles
     */
    public static function labelList(array $roles): string
    {
        $labels = array_map([self::class, 'label'], $roles);

        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
