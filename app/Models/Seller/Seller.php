<?php

namespace App\Models\Seller;

use App\Models\Product;
use App\Models\Shared\Category;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Seller extends Authenticatable
{
    use HasFactory, Notifiable;

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

    protected static function newFactory(): SellerFactory
    {
        return SellerFactory::new();
    }

    public function lineOfBusiness()
    {
        return $this->belongsTo(Category::class, 'line_of_business_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function storeInitials(): string
    {
        $letters = collect(preg_split('/\s+/', trim($this->business_name)) ?: [])
            ->filter()
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(2)
            ->implode('');

        return $letters !== '' ? $letters : 'S';
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
