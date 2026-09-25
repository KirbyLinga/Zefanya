<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — use markPaid() so the
     * payment state and paid_at stay consistent. Transitions arrive in a
     * later phase (COD collection on delivery).
     */
    protected $fillable = [
        'seller_order_id',
        'amount_minor',
        'method',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Payment is per seller_order, not per order.
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    // ── Controlled transitions ───────────────────────────────────────────────

    public function markPaid(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ])->save();
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedAmount(): string
    {
        return '₱'.number_format($this->amount_minor / 100, 2);
    }
}
