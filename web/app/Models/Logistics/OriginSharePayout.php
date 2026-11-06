<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One payment from Zefanya to an origin carrier for its 40% of cross-provider shipping fees. */
class OriginSharePayout extends Model
{
    public const PAID = 'PAID';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'parcel_count' => 'integer',
            'amount_minor' => 'integer',
            'paid_on' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by_admin_id');
    }

    public function codRemittances(): HasMany
    {
        return $this->hasMany(CodRemittance::class);
    }

    /** Append-only reversal; original payment details remain unchanged. */
    public function reversal(): HasOne
    {
        return $this->hasOne(OperationsAuditLog::class, 'entity_id')
            ->where('entity_type', 'origin_share_payout')
            ->where('action', 'origin_share_reversed');
    }
}
