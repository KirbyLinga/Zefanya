<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-visible record of a parcel that cannot be handed off because no
 * approved, active, serviceable destination partner exists.
 */
class ParcelHandoffException extends Model
{
    public const OPEN = 'OPEN';

    public const RESOLVED = 'RESOLVED';

    protected $fillable = [
        'seller_order_id',
        'origin_provider_id',
        'status',
        'destination_barangay_code',
        'reason',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function originProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_provider_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }
}
