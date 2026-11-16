<?php

namespace App\Models\Logistics;

use App\Enums\ParcelHandoffStatus;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ProviderHandoffSchemaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ParcelHandoff extends Model
{
    protected $guarded = ['*'];

    protected $attributes = ['status' => 'REQUESTED', 'direction' => 'forward'];

    protected function casts(): array
    {
        return [
            'status' => ParcelHandoffStatus::class,
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'recovered_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'transport_required' => 'boolean',
            'transport_assigned_at' => 'datetime',
        ];
    }

    /** Cancelled after it left the sending hub, and not yet verified anywhere. */
    public function awaitsRecovery(): bool
    {
        return $this->status === ParcelHandoffStatus::Cancelled
            && $this->dispatched_at !== null
            && $this->recovered_at === null
            && $this->cancelled_by_type !== 'admin';
    }

    /** The sending company's Courier who carries this handoff. */
    public function transferCourier(): BelongsTo
    {
        return $this->belongsTo(TransferCourier::class, 'transfer_courier_id');
    }

    protected static function booted(): void
    {
        static::saving(fn (self $handoff) => app(ProviderHandoffSchemaService::class)->validateHandoff($handoff));
        static::deleting(function (): void {
            throw ValidationException::withMessages(['handoff' => 'Historical handoffs cannot be deleted.']);
        });
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function isRequested(): bool
    {
        return $this->status === ParcelHandoffStatus::Requested;
    }

    public function isAccepted(): bool
    {
        return $this->status === ParcelHandoffStatus::Accepted;
    }

    public function isReceived(): bool
    {
        return $this->status === ParcelHandoffStatus::Received;
    }

    public function isRejected(): bool
    {
        return $this->status === ParcelHandoffStatus::Rejected;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            ParcelHandoffStatus::Requested,
            ParcelHandoffStatus::Accepted,
            ParcelHandoffStatus::InTransit,
        ], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            ParcelHandoffStatus::Requested => 'Requested',
            ParcelHandoffStatus::Accepted => 'Accepted — awaiting dispatch',
            ParcelHandoffStatus::InTransit => 'In transit',
            ParcelHandoffStatus::Rejected => 'Rejected',
            ParcelHandoffStatus::Received => 'Received',
            ParcelHandoffStatus::Cancelled => $this->awaitsRecovery() ? 'Cancelled — awaiting recovery scan' : 'Cancelled',
        };
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(LogisticsProviderLink::class, 'logistics_provider_link_id');
    }

    public function originProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_provider_id');
    }

    public function destinationProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'destination_provider_id');
    }

    public function sendingHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'sending_hub_id');
    }

    public function receivingHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'receiving_hub_id');
    }
}
