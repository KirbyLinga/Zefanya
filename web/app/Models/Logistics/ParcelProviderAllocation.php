<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class ParcelProviderAllocation extends Model
{
    protected $fillable = ['seller_order_id', 'logistics_provider_id', 'sequence', 'role', 'share_minor', 'status'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'share_minor' => 'integer'];
    }
}
