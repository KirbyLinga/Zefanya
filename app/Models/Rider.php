<?php

namespace App\Models;

use App\Models\Logistics\LogisticsProvider;
use Database\Factories\RiderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Rider extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'birthday', 'email', 'contact_no',
        'license_no', 'license_expiry', 'vehicle_type', 'plate_number',
        'drivers_license_path', 'or_cr_path',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'license_expiry' => 'date',
            'password' => 'hashed',
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

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
