<?php

namespace App\Models\Rider;

use App\Models\Concerns\HasEmailOtp;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use Database\Factories\Rider\RiderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Rider extends Authenticatable
{
    use HasEmailOtp, HasFactory, Notifiable, HasApiTokens;

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PENDING_ACTIVATION = 'pending_activation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DEACTIVATED = 'deactivated';

    /** Statuses that may be assigned parcels. */
    public const ASSIGNABLE_STATUSES = [self::STATUS_APPROVED, self::STATUS_ACTIVE];

    protected $fillable = [
        'first_name', 'last_name', 'birthday', 'email', 'contact_no',
        'license_no', 'license_expiry', 'vehicle_type', 'plate_number',
        'drivers_license_path', 'or_cr_path',
        'address_mode', 'province_code', 'province_name', 'municipality_code',
        'municipality_name', 'barangay_code', 'barangay_name', 'street',
        'house_number', 'address_detail', 'postal_code',
    ];

    protected $hidden = ['password', 'remember_token', 'email_verification_code', 'drivers_license_path', 'or_cr_path'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'license_expiry' => 'date',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'station_hub_id' => 'integer',
        ];
    }

    protected static function newFactory(): RiderFactory
    {
        return RiderFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'station_hub_id');
    }

    public function courier(): HasOne
    {
        return $this->hasOne(Courier::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isApprovedOrActive(): bool
    {
        return in_array($this->status, self::ASSIGNABLE_STATUSES, true);
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Whether this rider may sign in and open the operational pages: the
     * account is approved/active, the email is verified, the assigned Station
     * Hub is operational and owned by the same company, and a fleet Courier
     * record exists to carry assignments.
     */
    public function isOperational(): bool
    {
        return $this->isEligibleForAssignment() && $this->courier()->exists();
    }

    /**
     * A rider may receive parcels only when Logistics approved the account,
     * the email is verified, and the rider belongs to an approved, active
     * Station Hub owned by the same Logistics company.
     */
    public function isEligibleForAssignment(): bool
    {
        if (! $this->isApprovedOrActive() || $this->email_verified_at === null || $this->station_hub_id === null) {
            return false;
        }

        $station = $this->relationLoaded('station') ? $this->station : $this->station()->first();

        return $station !== null
            && $station->isOperational()
            && (int) $station->logistics_provider_id === (int) $this->logistics_provider_id;
    }

    public function scopeEligibleForAssignment(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('station_hub_id')
            ->whereHas('station', fn (Builder $station) => $station
                ->where('hub_type', LogisticsHub::STATION)
                ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                ->where('is_active', true));
    }
}
