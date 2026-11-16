<?php

namespace App\Models\Logistics;

use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Admin-confirmed lost parcel, with the Seller compensation its liable
 * Logistics provider owes. `logistics_provider_id` is the liable provider, so
 * an Admin decision also shows in that company's Activity log.
 */
class ParcelLossCase extends Model
{
    public const BASIS_BEFORE_RECEIPT = 'custody_before_receipt';

    public const BASIS_AFTER_RECEIPT = 'custody_after_receipt';

    public const BASIS_ADMIN = 'admin_assigned';

    public const BASIS_REVIEW = 'review_required';

    public const PENDING = 'pending';

    public const REVIEW_REQUIRED = 'review_required';

    public const PAID = 'paid';

    /** What Admin decides to do with a parcel found after it was confirmed lost. */
    public const DISPOSITIONS = [
        'keep_closed' => 'Keep the order closed as lost',
        'return_to_seller' => 'Return the parcel to the Seller (arranged manually)',
        'written_off' => 'Write the parcel off',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'compensation_paid_on' => 'date',
            'compensation_paid_at' => 'datetime',
            'recovered_at' => 'datetime',
            'disposition_at' => 'datetime',
            'merchandise_minor' => 'integer',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
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

    public function isRecovered(): bool
    {
        return $this->recovered_at !== null;
    }

    /** The saved merchandise value plus any audited adjustments. */
    public function netCompensationMinor(): int
    {
        return $this->merchandise_minor + (int) $this->adjustments->sum('amount_minor');
    }
}
