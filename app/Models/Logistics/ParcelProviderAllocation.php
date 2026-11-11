<?php

namespace App\Models\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit record: one provider's place in a 3+ provider delivery chain. */
class ParcelProviderAllocation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['share_minor' => 'integer', 'sequence' => 'integer'];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }
}
