<?php

namespace App\Models\Psgc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsgcRegion extends Model
{
    protected $table = 'psgc_regions';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'official_name',
    ];

    public function provinces(): HasMany
    {
        return $this->hasMany(PsgcProvince::class, 'region_code', 'code');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(PsgcCity::class, 'region_code', 'code');
    }
}
