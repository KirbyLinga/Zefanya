<?php

namespace App\Models\Admin;

use App\Enums\AdminRole;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use CanResetPassword, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * Mirror the migration default (`admins.role` defaults to 'admin') so a
     * freshly created model reports the same role as the persisted row.
     * Without it the in-memory instance keeps role = null until refreshed,
     * which trips EnsureAdminRole for code that authorizes right after
     * create() (and for `actingAs($admin)` in tests).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'admin',
    ];

    /**
     * Validate `role` on every create/update against the single AdminRole
     * enum. Throwing here (rather than silently coercing) means no code path
     * can persist an unknown role. There is no admin CRUD screen yet, so the
     * model is the one place every write must pass through.
     */
    protected static function booted(): void
    {
        static::saving(function (Admin $admin): void {
            if ($admin->role === null) {
                return;
            }

            if (AdminRole::tryFrom((string) $admin->role) === null) {
                throw new \InvalidArgumentException(
                    'Invalid admin role ['.$admin->role.']. Allowed: '.implode(', ', AdminRole::values()).'.'
                );
            }
        });
    }

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** True only after the admin completed the authenticator enrolment. */
    public function hasEnabledTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /**
     * @return list<string>
     */
    public function twoFactorRecoveryCodeHashes(): array
    {
        $codes = $this->two_factor_recovery_codes;

        return is_array($codes) ? array_values($codes) : [];
    }
}
