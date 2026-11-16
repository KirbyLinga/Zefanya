<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferCourierAssignment extends Model
{
    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'replaced_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(TransferCourier::class, 'transfer_courier_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(ParcelHandoff::class, 'parcel_handoff_id');
    }
}
