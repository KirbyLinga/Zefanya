<?php

namespace App\Models\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcelLossCase extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REVIEW_REQUIRED = 'review_required';

    public const BASIS_BEFORE_RECEIPT = 'before_receipt';

    public const BASIS_AFTER_RECEIPT = 'after_receipt';

    public const BASIS_REVIEW = 'review_required';

    public const BASIS_ADMIN = 'admin';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'merchandise_minor' => 'integer',
            'compensation_paid_at' => 'date',
            'recovered_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ParcelLossAdjustment::class);
    }

    public function isPaid(): bool
    {
        return $this->compensation_status === self::PAID;
    }

    public function netCompensationMinor(): int
    {
        return $this->merchandise_minor + (int) $this->adjustments()->sum('amount_minor');
    }
}
