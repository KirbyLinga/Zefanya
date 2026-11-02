<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodRemittance extends Model
{
    public const PENDING_REMITTANCE = 'PENDING_REMITTANCE';

    public const REMITTED_TO_LOGISTICS = 'REMITTED_TO_LOGISTICS';

    /** SQL twin of keptShippingMinor(), for queries joined to seller_orders. */
    public const KEPT_SHIPPING_SQL = 'CASE WHEN seller_orders.carrier_provider_id IS NOT NULL AND seller_orders.carrier_provider_id <> cod_remittances.logistics_provider_id THEN 0 ELSE seller_orders.shipping_fee_minor END';

    protected $fillable = [
        'payment_id',
        'seller_order_id',
        'logistics_provider_id',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_amount_minor' => 'integer',
            'confirmed_at' => 'datetime',
            'zefanya_received_at' => 'datetime',
        ];
    }

    /** Delivered by a company other than the carrier the buyer chose at checkout. */
    public function isCrossProvider(): bool
    {
        $carrierId = $this->sellerOrder?->carrier_provider_id;

        return $carrierId !== null && (int) $carrierId !== (int) $this->logistics_provider_id;
    }

    /**
     * Shipping fee the collecting company may keep. A cross-provider fee has no
     * agreed split yet, so it goes to Zefanya in full, pending allocation.
     */
    public function keptShippingMinor(): int
    {
        return $this->isCrossProvider() ? 0 : (int) $this->sellerOrder?->shipping_fee_minor;
    }

    public function dueToZefanyaMinor(): int
    {
        return $this->amount_minor - $this->keptShippingMinor();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }
}
