<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Logistics\ParcelReturn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
            'collected_by_id' => 'integer',
            'collected_amount_minor' => 'integer',
            'collected_at' => 'datetime',
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
        return $this->guardCodReturnWrite(fn (): bool => $this->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ])->save());
    }

    /**
     * True when cash still has to be collected from the buyer at the door.
     */
    public function requiresCollection(): bool
    {
        return $this->method === PaymentMethod::Cod
            && $this->status === PaymentStatus::Pending;
    }

    /**
     * Record the cash handed over by the buyer to the rider.
     *
     * Idempotent: an already-paid payment is left untouched and returns false,
     * so a double-clicked delivery cannot record the collection twice. The
     * amount is re-checked here (not only in the form request) as a hard
     * invariant of the payments row.
     *
     * @throws \InvalidArgumentException when the method or amount is wrong.
     */
    public function recordCodCollection(int $amountMinor, string $collectedByType, int $collectedById): bool
    {
        if ($this->method !== PaymentMethod::Cod) {
            throw new \InvalidArgumentException('Only COD payments are collected at the door.');
        }

        return $this->guardCodReturnWrite(function () use ($amountMinor, $collectedByType, $collectedById): bool {
            if ($this->status === PaymentStatus::Paid) {
                return false;
            }
            if ($amountMinor !== (int) $this->amount_minor) {
                throw new \InvalidArgumentException('The amount collected does not match the amount due.');
            }

            return $this->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'collected_by_type' => $collectedByType,
                'collected_by_id' => $collectedById,
                'collected_amount_minor' => $amountMinor,
                'collected_at' => now(),
            ])->save();
        });
    }

    /** Share the return workflow's parcel lock; never authorize from cached relationships. */
    private function guardCodReturnWrite(\Closure $write): bool
    {
        if ($this->method !== PaymentMethod::Cod) {
            return $write();
        }

        return DB::transaction(function () use ($write): bool {
            SellerOrder::query()->whereKey($this->seller_order_id)->lockForUpdate()->firstOrFail();
            if (ParcelReturn::query()->where('seller_order_id', $this->seller_order_id)->lockForUpdate()->first() !== null) {
                throw new \InvalidArgumentException('COD cannot be collected or settled for an undelivered return.');
            }

            return $write();
        }, 3);
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedAmount(): string
    {
        return '₱'.number_format($this->amount_minor / 100, 2);
    }
}
