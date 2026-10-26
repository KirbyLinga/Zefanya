<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\Courier;
use App\Services\LogisticsHubService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class LogisticsHub extends Model
{
    use HasFactory;

    public const PROVINCE = 'province_hub';

    public const STATION = 'station_hub';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'logistics_provider_id',
        'parent_hub_id',
        'name',
        'hub_type',
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
        'location_details',
        'is_active',
        'approval_status',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'approved_by',
        'rejected_by',
        'archived_at',
        'active_province_slot',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
            'active_province_slot' => 'integer',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isLiveProvinceHub(): bool
    {
        return $this->hub_type === self::PROVINCE
            && $this->archived_at === null
            && (int) $this->active_province_slot === 1;
    }

    public function scopeNonArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeLiveProvince(Builder $query): Builder
    {
        return $query->where('hub_type', self::PROVINCE)
            ->whereNull('archived_at')
            ->where('active_province_slot', 1);
    }

    protected static function booted(): void
    {
        // First non-archived Province Hub for a provider claims the live slot.
        static::creating(function (self $hub): void {
            if ($hub->hub_type !== self::PROVINCE || $hub->archived_at !== null) {
                return;
            }

            if ($hub->active_province_slot !== null) {
                return;
            }

            $hasLive = self::query()
                ->where('logistics_provider_id', $hub->logistics_provider_id)
                ->where('hub_type', self::PROVINCE)
                ->where('active_province_slot', 1)
                ->whereNull('archived_at')
                ->exists();

            if (! $hasLive) {
                $hub->active_province_slot = 1;
            }
        });

        static::saving(function (self $hub): void {
            if ($hub->hub_type === self::PROVINCE) {
                if ($hub->parent_hub_id !== null) {
                    throw ValidationException::withMessages(['hub_type' => 'Province Hubs cannot have a parent hub.']);
                }

                return;
            }

            if ($hub->hub_type !== self::STATION || $hub->parent_hub_id === null || blank($hub->municipality)) {
                throw ValidationException::withMessages(['hub_type' => 'Station Hubs require a parent Province Hub and municipality.']);
            }

            $parent = self::query()->find($hub->parent_hub_id);
            if (! $parent || $parent->hub_type !== self::PROVINCE || (int) $parent->logistics_provider_id !== (int) $hub->logistics_provider_id) {
                throw ValidationException::withMessages(['parent_hub_id' => 'Select a Province Hub owned by this Logistics company.']);
            }

            if ($parent->isArchived() || ! $parent->isLiveProvinceHub()) {
                throw ValidationException::withMessages([
                    'parent_hub_id' => 'Station Hubs can only be linked to the provider\'s live Province Hub.',
                ]);
            }

            $provider = LogisticsProvider::query()->find($hub->logistics_provider_id);
            // Only enforce when PSGC codes are present — legacy fixtures without codes
            // still attach under the live Province Hub; controllers require codes for new writes.
            if ($provider !== null && $provider->hasVerifiedProvince()
                && filled($parent->province_code)
                && (string) $parent->province_code !== (string) $provider->province_code) {
                throw ValidationException::withMessages([
                    'parent_hub_id' => 'Station Hubs must stay inside the provider\'s verified province.',
                ]);
            }

            if ($provider !== null && $provider->hasVerifiedProvince()
                && filled($hub->province_code)
                && (string) $hub->province_code !== (string) $provider->province_code) {
                throw ValidationException::withMessages([
                    'province_code' => 'Station Hub province must match the provider\'s verified province.',
                ]);
            }
        });
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_REJECTED;
    }

    public function isOperational(): bool
    {
        return $this->isApproved() && $this->is_active && ! $this->isArchived();
    }

    public function hasValidBarangayCoverage(): bool
    {
        if ($this->relationLoaded('areas')) {
            return $this->areas->contains(fn (Area $area) => (int) $area->logistics_provider_id === (int) $this->logistics_provider_id
                && $area->is_active && filled($area->barangay_code) && $area->coverage_validated_at !== null);
        }
        if (! (new LogisticsHubService)->coverageSchemaAvailable()) {
            return false;
        }

        return $this->areas()->where('logistics_provider_id', $this->logistics_provider_id)
            ->where('is_active', true)->whereNotNull('coverage_validated_at')->whereNotNull('barangay_code')
            ->where('barangay_code', '<>', '')->exists();
    }

    public function isReadyForNewAssignments(): bool
    {
        return $this->hub_type === self::STATION && $this->isOperational()
            && $this->parent?->isOperational() && $this->hasValidBarangayCoverage();
    }

    public function fullAddress(): string
    {
        $parts = collect([
            $this->building,
            $this->street,
            $this->landmark,
            $this->barangay_name,
            $this->municipality,
            $this->province_name,
            $this->region_name,
            $this->postal_code,
        ])->filter(fn ($v) => filled($v))->values();

        if ($parts->isNotEmpty()) {
            return $parts->join(', ');
        }

        return collect([$this->location_details, $this->municipality, $this->province_name])
            ->filter(fn ($v) => filled($v))
            ->join(', ');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('approval_status', self::APPROVAL_PENDING);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->whereNull('archived_at');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_hub_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'rejected_by');
    }

    public function stations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_hub_id')->where('hub_type', self::STATION);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class, 'station_hub_id');
    }

    public function outgoingManifests(): HasMany
    {
        return $this->hasMany(Manifest::class, 'origin_hub_id');
    }

    public function outgoingHandoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class, 'sending_hub_id');
    }

    public function incomingHandoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class, 'receiving_hub_id');
    }

    public function incomingManifests(): HasMany
    {
        return $this->hasMany(Manifest::class, 'destination_hub_id');
    }

    public function locationChanges(): HasMany
    {
        return $this->hasMany(LogisticsHubLocationChange::class);
    }

    public function pendingLocationChange(): HasOne
    {
        return $this->hasOne(LogisticsHubLocationChange::class)
            ->where('status', LogisticsHubLocationChange::STATUS_PENDING)
            ->latestOfMany();
    }

    public function couriers(): HasManyThrough
    {
        return $this->hasManyThrough(Courier::class, Area::class, 'station_hub_id', 'area_id', 'id', 'id');
    }
}
