<?php

namespace App\Models\Rider;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One payment from a Logistics company to its rider, covering a batch of earnings. */
class RiderPayout extends Model
{
    public const PAID = 'PAID';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'earning_count' => 'integer',
            'amount_minor' => 'integer',
            'paid_on' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(RiderEarning::class);
    }

    /** Append-only reversal; original payment details remain unchanged. */
    public function reversal(): HasOne
    {
        return $this->hasOne(OperationsAuditLog::class, 'entity_id')
            ->where('entity_type', 'rider_payout')
            ->where('action', 'rider_payout_reversed');
    }
}
