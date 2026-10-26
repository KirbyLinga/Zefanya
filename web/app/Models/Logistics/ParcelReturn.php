<?php

namespace App\Models\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcelReturn extends Model
{
    protected $fillable = [
        'seller_order_id',
        'status',
        'origin_provider_id',
        'origin_hub_id',
        'reason',
        'requested_at',
        'barangay_code',
        'address',
        'evidence',
    ];

    protected $hidden = ['handover_code_hash', 'handover_nonce'];

    protected function casts(): array
    {
        return ['status' => ParcelReturnStatus::class, 'delivery_attempts' => 'integer', 'seller_address' => 'array', 'requested_at' => 'datetime', 'origin_arrived_at' => 'datetime', 'seller_received_at' => 'datetime', 'handover_expires_at' => 'datetime', 'stock_restored_at' => 'datetime', 'financial_review_required' => 'boolean'];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function originProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_provider_id');
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_hub_id');
    }

    public function receivingSeller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'received_by_seller_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelReturnEvent::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status !== ParcelReturnStatus::Completed;
    }

    public function scopeVisibleToProvider(Builder $query, int $providerId): Builder
    {
        return $query->where(fn ($visible) => $visible->where('origin_provider_id', $providerId)
            ->orWhereHas('sellerOrder', fn ($parcel) => $parcel->heldBy($providerId))
            ->orWhereHas('sellerOrder.parcelHandoffs', fn ($handoff) => $handoff->where('direction', 'return')->where('origin_provider_id', $providerId)));
    }
}
