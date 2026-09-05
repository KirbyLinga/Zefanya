<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Seller extends Authenticatable
{
    use Notifiable;

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
        'business_name',
        'line_of_business_id',
        'upload_id_path',
        'business_permit_path',
        'status',
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
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function lineOfBusiness()
    {
        return $this->belongsTo(Category::class, 'line_of_business_id');
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
        return $this->status === 'approved';
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
            'status' => 'pending_approval',
        ])->save();
    }
}