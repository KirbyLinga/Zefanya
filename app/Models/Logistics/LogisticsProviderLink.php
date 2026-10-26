<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Services\ProviderHandoffSchemaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class LogisticsProviderLink extends Model
{
    public const PENDING = 'PENDING';

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const REJECTED = 'REJECTED';

    public const REVOKED = 'REVOKED';

    /** Statuses that keep historical rows saveable after provider eligibility changes. */
    public const TERMINAL = [self::INACTIVE, self::REJECTED, self::REVOKED];

    // Relationships, consent, and approval must be written explicitly by trusted services.
    protected $guarded = ['*'];

    protected $attributes = ['status' => self::PENDING, 'priority' => 0];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'origin_consented_at' => 'datetime',
            'destination_consented_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $link) => app(ProviderHandoffSchemaService::class)->validateLink($link));
        static::deleting(function (): void {
            throw ValidationException::withMessages(['link' => 'Provider links are retained for audit; deactivate instead.']);
        });
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::REVOKED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::REJECTED => 'Rejected',
            self::REVOKED => 'Revoked',
            self::INACTIVE => 'Inactive',
            default => 'Unknown',
        };
    }

    public function originProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_provider_id');
    }

    public function destinationProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'destination_provider_id');
    }

    public function originConsenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_consented_by');
    }

    public function destinationConsenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'destination_consented_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'rejected_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'revoked_by');
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class);
    }
}
