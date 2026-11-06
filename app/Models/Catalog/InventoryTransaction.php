<?php

namespace App\Models\Catalog;

use App\Models\Shared\SellerOrder;
use App\Services\Seller\InventoryAlertService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger (project rule 7). Rows are written inside the same
 * transaction that moved the stock and are never updated or deleted.
 */
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (self $movement) => app(InventoryAlertService::class)->record($movement));
    }

    protected $fillable = [
        'product_id',
        'seller_order_id',
        'variant_id',
        'quantity_before',
        'quantity_after',
        'delta',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'delta' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
