<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actionable record of inventory that could not be restored because a
 * product variant referenced by a returned order item no longer exists.
 *
 * The verified Seller physical receipt is preserved; only the stock
 * restoration is blocked pending manual reconciliation.
 */
class InventoryException extends Model
{
    public const OPEN = 'OPEN';

    public const RESOLVED = 'RESOLVED';

    protected $fillable = [
        'seller_order_id',
        'variant_id',
        'quantity',
        'status',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }
}
