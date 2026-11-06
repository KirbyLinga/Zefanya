<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One payment from a Logistics company to Zefanya: collected cash minus the shipping it keeps. */
class LogisticsRemittance extends Model
{
    public const SUBMITTED = 'SUBMITTED';

    public const CONFIRMED = 'CONFIRMED';

    public const REJECTED = 'REJECTED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'parcel_count' => 'integer',
            'cod_total_minor' => 'integer',
            'shipping_total_minor' => 'integer',
            'amount_minor' => 'integer',
            'received_amount_minor' => 'integer',
            'short_minor' => 'integer',
            'left_out_count' => 'integer',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** Admin confirmed only some of the parcels because less money arrived than stated. */
    public function isPartlyConfirmed(): bool
    {
        return $this->status === self::CONFIRMED && $this->left_out_count > 0;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by_admin_id');
    }

    public function codRemittances(): HasMany
    {
        return $this->hasMany(CodRemittance::class);
    }
}
