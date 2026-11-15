<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * Courier Mobile app access for one Transfer Courier record.
 *
 * The TransferCourier stays the operational record (assignments, dispatch,
 * history). This row only holds sign-in state. It has no session guard and no
 * web route: it authenticates through the Courier API with a bearer token
 * whose only ability is `courier`. All writes go through
 * App\Services\Logistics\CourierAccountService.
 */
class CourierAccount extends Authenticatable
{
    use HasApiTokens;

    public const PENDING = 'pending_activation';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    public const ABILITY = 'courier';

    protected $table = 'courier_accounts';

    protected $guarded = ['*'];

    protected $hidden = [
        'password', 'activation_code_hash', 'activation_expires_at', 'activation_attempts',
        'credential_generation', 'session_generation', 'current_token_id',
    ];

    protected function casts(): array
    {
        return [
            'activation_expires_at' => 'datetime',
            'activation_attempts' => 'integer',
            'credential_generation' => 'integer',
            'session_generation' => 'integer',
            'current_token_id' => 'integer',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The binding to one operational Courier, and the login ID shown to
        // that person, never change after creation.
        static::updating(function (self $account): void {
            if ($account->isDirty(['transfer_courier_id', 'login_id'])) {
                throw new LogicException('A Courier account cannot be moved to another Courier or login ID.');
            }
        });
    }

    public function transferCourier(): BelongsTo
    {
        return $this->belongsTo(TransferCourier::class, 'transfer_courier_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** No remember-me cookie exists for Courier accounts. */
    public function getRememberTokenName()
    {
        return '';
    }
}
