<?php

namespace App\Models\Psgc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsgcBarangay extends Model
{
    protected $table = 'psgc_barangays';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'city_code',
        'name',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(PsgcCity::class, 'city_code', 'code');
    }
}
