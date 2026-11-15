<?php

namespace App\Models\Logistics;

use App\Models\Admin\Admin;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Zefanya's cost for a platform-funded exception refund. Never a Seller debt, a Logistics
 * charge or a change to commission. Rows are append-only: a void adds a linked reversal.
 */
class PlatformRefundExpense extends Model
{
    public const EXPENSE = 'expense';

    public const REVERSAL = 'reversal';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime'];
    }

    public static function available(): bool
    {
        return RuntimeSchema::hasTable('platform_refund_expenses');
    }

    public function parcelReturn(): BelongsTo
    {
        return $this->belongsTo(ParcelReturn::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refundEvent(): BelongsTo
    {
        return $this->belongsTo(ParcelReturnEvent::class, 'refund_event_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
