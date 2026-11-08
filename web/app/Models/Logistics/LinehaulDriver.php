<?php

namespace App\Models\Logistics;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class LinehaulDriver extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'email'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'on_duty' => 'boolean',
            'duty_checked_at' => 'datetime', 'last_seen_at' => 'datetime', 'duty_location' => 'array'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function homeHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'home_hub_id');
    }

    public function latestDutyCheck(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LinehaulDutyCheck::class)->latestOfMany();
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isOperational(): bool
    {
        return $this->status === 'active' && $this->isEmailVerified()
            && $this->provider?->isApproved() && $this->provider->archived_at === null
            && (int) $this->homeHub?->logistics_provider_id === (int) $this->logistics_provider_id
            && $this->homeHub?->isOperational();
    }
}
