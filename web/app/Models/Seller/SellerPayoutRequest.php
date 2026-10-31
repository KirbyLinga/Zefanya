<?php

namespace App\Models\Seller;

use App\Models\Admin\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPayoutRequest extends Model
{
    public const PENDING = 'PENDING';

    public const CANCELLED = 'CANCELLED';

    public const PAID = 'PAID';

    public const REJECTED = 'REJECTED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'cancelled_at' => 'datetime', 'paid_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by_admin_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SellerPayoutAllocation::class);
    }
}
