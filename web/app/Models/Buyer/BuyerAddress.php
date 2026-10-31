<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerAddress extends Model
{
    protected $fillable = [
        'buyer_id',
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
        'is_default',
        'default_marker',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'default_marker' => 'integer',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function summary(): string
    {
        return collect([
            $this->house_number,
            $this->street,
            $this->barangay_name,
            $this->municipality_name,
            $this->province_name,
            $this->postal_code,
        ])->filter(fn ($part) => filled($part))->implode(', ');
    }
}
