<?php

namespace App\Models\Psgc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsgcCity extends Model
{
    protected $table = 'psgc_cities';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'region_code',
        'province_code',
        'name',
        'postal_code',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(PsgcRegion::class, 'region_code', 'code');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(PsgcProvince::class, 'province_code', 'code');
    }

    public function barangays(): HasMany
    {
        return $this->hasMany(PsgcBarangay::class, 'city_code', 'code');
    }
}
