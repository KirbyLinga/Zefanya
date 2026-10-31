<?php

namespace App\Models\Buyer;

use App\Models\Concerns\HasEmailOtp;
use App\Models\Concerns\HasRegistrationArchive;
use App\Models\Shared\Conversation;
use App\Support\WaybillPrivacy;
use Carbon\Carbon;
use Database\Factories\Buyer\BuyerFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Buyer extends Authenticatable
{
    use CanResetPassword, HasApiTokens, HasEmailOtp, HasFactory, HasRegistrationArchive, Notifiable;

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
        'postal_code',
        'upload_id_path',
        'email_verification_code',
        'email_verification_expires_at',
        'status',
        'settings',
        'otp_attempts',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'pending_email_code',
        'pending_password',
        'pending_password_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'pending_password_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'json',
        ];
    }

    /**
     * Age is derived from birthday so a stored column cannot go stale.
     */
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

    /** Public review byline. Keeps the first and last character of each name part. */
    public function maskedName(): string
    {
        return WaybillPrivacy::maskPersonName($this->fullName());
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    protected static function newFactory(): BuyerFactory
    {
        return BuyerFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function wishlist(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(BuyerAddress::class);
    }

    public function isVerificationLinkExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    /**
     * Marks the email verified and moves the buyer into the admin queue.
     * Called by VerifyBuyerOtpController once the token checks out.
     */
    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => 'pending',
            'otp_attempts' => 0,
        ])->save();
    }
}
