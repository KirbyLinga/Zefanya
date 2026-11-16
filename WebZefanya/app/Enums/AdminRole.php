<?php

namespace App\Enums;

/**
 * The only roles an admin account may hold.
 *
 * `super_admin` passes every authorization check; every other role is granted
 * per-area access by the `admin.role` middleware. Kept in one place so the
 * database, validation and the middleware can never drift apart.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Support = 'support';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Moderator => 'Moderator',
            self::Support => 'Support',
        };
    }
}
