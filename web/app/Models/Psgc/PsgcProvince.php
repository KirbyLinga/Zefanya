<?php

namespace App\Models\Psgc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsgcProvince extends Model
{
    protected $table = 'psgc_provinces';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'region_code',
        'name',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(PsgcRegion::class, 'region_code', 'code');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(PsgcCity::class, 'province_code', 'code');
    }
}
