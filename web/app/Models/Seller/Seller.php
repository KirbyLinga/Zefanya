<?php

namespace App\Models\Seller;

use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Concerns\HasEmailOtp;
use App\Models\Concerns\HasRegistrationArchive;
use App\Models\Shared\Category;
use App\Models\Shared\Conversation;
use App\Models\Shared\SellerOrder;
use Carbon\Carbon;
use Database\Factories\Seller\SellerFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Seller extends Authenticatable
{
    public const VACATION_MESSAGE = 'This store is on vacation. Purchasing is temporarily unavailable.';

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
        'business_name',
        'tin',
        'seller_type',
        'sec_certificate_path',
        'store_description',
        'contact_email',
        'region_code',
        'line_of_business_id',
        'upload_id_path',
        'business_permit_path',
        'status',
        'otp_attempts',
    ];

    protected $hidden = [
        'store_name_key',
        'password',
        'remember_token',
        'email_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'is_on_vacation' => 'boolean',
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): SellerFactory
    {
        return SellerFactory::new();
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

    public function lineOfBusiness()
    {
        return $this->belongsTo(Category::class, 'line_of_business_id');
    }

    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, Product::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Per-shop fulfilment slices of any buyer order this seller is part of.
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
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

    public static function normalizedStoreName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name));
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
