<?php

namespace App\Models\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Concerns\HasEmailOtp;
use App\Models\Concerns\HasRegistrationArchive;
use Carbon\Carbon;
use Database\Factories\Logistics\LogisticsProviderFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class LogisticsProvider extends Authenticatable
{
    use CanResetPassword, HasEmailOtp, HasFactory, HasRegistrationArchive, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'password',
        'contact_no',
        'birthday',
        'age',
        'business_name',
        'tin',
        'sec_number',
        'service_areas',
        'region_code',
        'address_mode',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'street',
        'house_number',
        'address_detail',
        'postal_code',
        'upload_id_path',
        'dti_permit_path',
        'status',
        'otp_attempts',
        'admin_remarks',
        // Set only by the admin approval/rejection actions (never from request
        // data — RegistrationController writes these explicitly). Without them in
        // the allow-list Model::update() silently drops the decision metadata.
        'rejection_reason',
        'approved_by',
        'approved_at',
        // Province lock metadata — written only by Admin / ProviderProvinceService.
        'province_verified_at',
        'province_verified_by',
    ];

    public static function normalizedCompanyName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name));
    }

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'service_areas' => 'array',
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'province_verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'password' => 'hashed',
            'status' => LogisticsProviderStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // After Admin verification, province_code is read-only to Logistics.
        // Admin changes go through ProviderProvinceService::changeProvinceByAdmin().
        static::updating(function (self $provider): void {
            if ($provider->getOriginal('province_verified_at') === null) {
                return;
            }

            if (! $provider->isDirty(['province_code', 'province_name'])) {
                return;
            }

            if (app()->bound('admin.changing_provider_province')) {
                return;
            }

            abort(403, 'Your service province is locked. Contact an administrator to change it.');
        });
    }

    protected static function newFactory(): LogisticsProviderFactory
    {
        return LogisticsProviderFactory::new();
    }

    /** Age is always derived from birthday. */
    protected function age(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->birthday === null) {
                return null;
            }

            return Carbon::parse($this->birthday)->age;
        });
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function hubs(): HasMany
    {
        return $this->hasMany(LogisticsHub::class, 'logistics_provider_id');
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class, 'logistics_provider_id');
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(LogisticsProviderLink::class, 'origin_provider_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(LogisticsProviderLink::class, 'destination_provider_id');
    }

    public function outgoingHandoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class, 'origin_provider_id');
    }

    public function incomingHandoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class, 'destination_provider_id');
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === LogisticsProviderStatus::Approved;
    }

    public function hasVerifiedProvince(): bool
    {
        return $this->province_verified_at !== null && filled($this->province_code);
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => LogisticsProviderStatus::Pending,
            'otp_attempts' => 0,
        ])->save();
    }
}
