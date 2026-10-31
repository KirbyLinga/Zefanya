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
