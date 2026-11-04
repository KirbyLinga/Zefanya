<?php

namespace App\Models\Logistics;

use App\Enums\LogisticsProviderStatus;
use Database\Factories\LogisticsProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class LogisticsProvider extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'google_sub',
        'password',
        'contact_no',
        'birthday',
        'age',
        'business_name',
        'vehicle_type',
        'plate_number',
        'license_number',
        'license_expiry',
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
        'hub',
        'upload_id_path',
        'license_path',
        'or_cr_path',
        'dti_permit_path',
        'is_online',
        'reverification_required',
    ];

    /** @var list<string> */
    protected $guarded = [
        'status',
        'email_verified_at',
        'is_admin',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'license_expiry' => 'date',
            'is_online' => 'boolean',
            'reverification_required' => 'boolean',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'status' => LogisticsProviderStatus::class,
        ];
    }

    protected static function newFactory(): LogisticsProviderFactory
    {
        return LogisticsProviderFactory::new();
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === LogisticsProviderStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === LogisticsProviderStatus::Rejected;
    }

    public function isSuspended(): bool
    {
        return $this->status === LogisticsProviderStatus::Suspended;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === LogisticsProviderStatus::PendingApproval;
    }

    public function isPastEmailVerification(): bool
    {
        return $this->status !== LogisticsProviderStatus::PendingVerification;
    }

    public function isOtpExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    public function issueOtp(): string
    {
        $otp = (string) random_int(100000, 999999);

        $this->forceFill([
            'email_verification_code' => hash('sha256', $otp),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $otp;
    }

    public function otpMatches(string $submitted): bool
    {
        return $this->email_verification_code !== null
            && hash_equals($this->email_verification_code, hash('sha256', $submitted));
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => LogisticsProviderStatus::PendingApproval,
        ])->save();
    }
}
