<?php

namespace App\Models;

use App\Models\Concerns\HasEmailOtp;
use App\Models\Concerns\HasRegistrationArchive;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Registration identity only: R02 owns approval and operational courier creation. */
class RiderApplication extends Authenticatable
{
    use HasEmailOtp, HasRegistrationArchive, \Illuminate\Database\Eloquent\Factories\HasFactory, Notifiable;

    protected $fillable = [
        'last_name', 'first_name', 'middle_initial', 'sex', 'email', 'password',
        'contact_no', 'birthday', 'province_code', 'province_name',
        'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name',
        'street', 'house_number', 'address_detail', 'postal_code', 'vehicle_type', 'plate_number',
    ];

    protected $hidden = ['password', 'remember_token', 'email_verification_code', 'upload_id_path', 'or_cr_path'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', 'birthday' => 'date', 'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->birthday?->age);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->middle_initial ? $this->middle_initial.'. ' : '').$this->last_name);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->email_verified_at !== null;
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(), 'email_verification_code' => null,
            'email_verification_expires_at' => null, 'status' => 'pending', 'otp_attempts' => 0,
        ])->save();
    }
}
