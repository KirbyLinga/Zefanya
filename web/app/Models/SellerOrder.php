<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'order_id',
        'seller_id',
        'subtotal_minor',
        'assigned_courier_id',
        'delivery_fee_minor',
        'delivery_area',
        'tracking_number',
    ];

    /**
     * The delivery OTP is a bearer secret until the handover. It is never
     * serialized, even by accident: only the fact that one exists is exposed.
     */
    protected $hidden = ['delivery_otp_hash', 'delivery_proof_path'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'status' => OrderStatus::class,
            'delivery_attempts' => 'integer',
            'delivery_otp_attempts' => 'integer',
            'delivery_otp_locked_until' => 'datetime',
            'delivery_otp_expires_at' => 'datetime',
            'delivery_otp_used_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The checkout-level order this fulfilment unit belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The shop fulfilling this slice of the order.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * The logistics provider assigned to deliver this parcel.
     */
    public function assignedCourier(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'assigned_courier_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Payment is per seller_order, not per order.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }

    // ── Pipeline Transition Helpers ──────────────────────────────────────────

    public function confirm(): bool
    {
        return $this->forceFill(['status' => OrderStatus::Confirmed])->save();
    }

    public function markPreparing(): bool
    {
        return $this->forceFill(['status' => OrderStatus::Preparing])->save();
    }

    public function markReadyForPickup(?string $trackingNumber = null): bool
    {
        $tracking = $trackingNumber ?? ('ZEF-'.now()->format('ymd').'-'.strtoupper(Str::random(4)));

        return $this->forceFill([
            'status' => OrderStatus::ReadyForPickup,
            'tracking_number' => $tracking,
        ])->save();
    }

    public function assignCourier(LogisticsProvider $courier, int $feeMinor = 8500, ?string $area = null, ?OrderStatus $status = null): bool
    {
        $payload = [
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => $feeMinor,
            'delivery_area' => $area ?? ($this->order?->shipping_address['province'] ?? 'Metro Manila'),
        ];
        if ($status !== null) {
            $payload['status'] = $status;
        }

        return $this->forceFill($payload)->save();
    }
}
