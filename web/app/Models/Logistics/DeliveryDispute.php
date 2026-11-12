<?php

namespace App\Models\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryDispute extends Model
{
    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const ESCALATED = 'escalated';

    public const RESOLVED = 'resolved';

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'already_paid_minor' => 'integer'];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
