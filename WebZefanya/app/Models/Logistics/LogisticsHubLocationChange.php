<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsHubLocationChange extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'logistics_hub_id',
        'status',
        'region_code',
        'region_name',
        'province_code',
        'province_name',
        'city_municipality_code',
        'municipality',
        'barangay_code',
        'barangay_name',
        'street',
        'building',
        'landmark',
        'postal_code',
        'requested_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'logistics_hub_id');
    }

    public function proposedAddress(): string
    {
        return collect([
            $this->building,
            $this->street,
            $this->landmark,
            $this->barangay_name,
            $this->municipality,
            $this->province_name,
            $this->region_name,
            $this->postal_code,
        ])->filter(fn ($value) => filled($value))->join(', ');
    }
}
