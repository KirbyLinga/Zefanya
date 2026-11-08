<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class LinehaulProfile extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['documents'];

    protected function casts(): array
    {
        return ['license_expiry' => 'date', 'license_codes' => 'array', 'documents' => 'array',
            'capacity_kg' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }
}
