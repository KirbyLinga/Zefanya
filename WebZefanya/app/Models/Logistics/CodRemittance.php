<?php

namespace App\Models\Logistics;

use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ProviderAllocationService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodRemittance extends Model
{
    public const PENDING_REMITTANCE = 'PENDING_REMITTANCE';

    public const REMITTED_TO_LOGISTICS = 'REMITTED_TO_LOGISTICS';

    /** Part of the parcel cash was received; the shortage is still outstanding. */
    public const PARTIALLY_REMITTED = 'PARTIALLY_REMITTED';

    /** Cross-provider fee split: the origin carrier picks up, sorts and dispatches. */
    public const ORIGIN_SHARE_PERCENT = 40;

    /** Distinct financial participants, not hub legs or unreceived/planned handoffs. */
    public const MULTI_PROVIDER_SQL = "(SELECT COUNT(*) FROM logistics_providers allocation_provider WHERE allocation_provider.id = seller_orders.carrier_provider_id OR allocation_provider.id = cod_remittances.logistics_provider_id OR EXISTS (SELECT 1 FROM parcel_handoffs allocation_handoff WHERE allocation_handoff.seller_order_id = seller_orders.id AND allocation_handoff.direction = 'forward' AND allocation_handoff.status = 'RECEIVED' AND (allocation_handoff.origin_provider_id = allocation_provider.id OR allocation_handoff.destination_provider_id = allocation_provider.id))) >= 3";

    public const ALLOCATION_PENDING_SQL = '('.self::MULTI_PROVIDER_SQL.') AND (cod_remittances.zefanya_received_at IS NULL OR cod_remittances.kept_shipping_minor IS NULL)';

    /**
     * Today's rule for a row with no snapshot yet. Fee x 0.4 never lands on half
     * a centavo, so ROUND matches Money::percentage() on every database.
     */
    private const CURRENT_ORIGIN_SHARE_SQL = 'CASE WHEN seller_orders.carrier_provider_id IS NOT NULL AND seller_orders.carrier_provider_id <> cod_remittances.logistics_provider_id THEN ROUND(seller_orders.shipping_fee_minor * 40 / 100.0) ELSE 0 END';

    /** SQL twin of originShareMinor(), for queries joined to seller_orders. */
    public const ORIGIN_SHARE_SQL = 'CASE WHEN '.self::MULTI_PROVIDER_SQL.' THEN CASE WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN cod_remittances.origin_share_minor ELSE NULL END ELSE COALESCE(cod_remittances.origin_share_minor, '.self::CURRENT_ORIGIN_SHARE_SQL.') END';

    /** SQL twin of keptShippingMinor(), for queries joined to seller_orders. */
    public const KEPT_SHIPPING_SQL = 'CASE WHEN '.self::MULTI_PROVIDER_SQL.' THEN CASE WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN cod_remittances.kept_shipping_minor ELSE NULL END ELSE COALESCE(cod_remittances.kept_shipping_minor, seller_orders.shipping_fee_minor - '.self::CURRENT_ORIGIN_SHARE_SQL.') END';

    protected $fillable = [
        'payment_id',
        'seller_order_id',
        'logistics_provider_id',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'kept_shipping_minor' => 'integer',
            'origin_share_minor' => 'integer',
            'received_amount_minor' => 'integer',
            'shortage_minor' => 'integer',
            'shortage_recovered_minor' => 'integer',
            'confirmed_at' => 'datetime',
            'rider_handover_declared_at' => 'datetime',
            'shortage_recovered_at' => 'datetime',
            'zefanya_received_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'destination_share_minor' => 'integer',
            'destination_paid_on' => 'date',
        ];
    }

    /** Delivered by a company other than the carrier the buyer chose at checkout. */
    public function isCrossProvider(): bool
    {
        $carrierId = $this->sellerOrder?->carrier_provider_id;

        return $carrierId !== null && (int) $carrierId !== (int) $this->logistics_provider_id;
    }

    /**
     * Shipping fee the collecting company may keep. On a cross-provider parcel it
     * is the destination's 60% share for receiving, sorting and final delivery.
     */
    public function keptShippingMinor(): ?int
    {
        if ($this->allocationRequiresApproval()) {
            return $this->zefanya_received_at !== null ? $this->kept_shipping_minor : null;
        }

        return $this->kept_shipping_minor
            ?? (int) $this->sellerOrder?->shipping_fee_minor - $this->currentOriginShareMinor();
    }

    /**
     * The origin carrier's 40% of a cross-provider fee. It is in the cash sent to
     * Zefanya, which owes it to the carrier the buyer chose at checkout.
     */
    public function originShareMinor(): ?int
    {
        if ($this->allocationRequiresApproval()) {
            return $this->zefanya_received_at !== null ? $this->origin_share_minor : null;
        }

        return $this->origin_share_minor ?? $this->currentOriginShareMinor();
    }

    /** Freeze today's split on a new row. An existing snapshot is never changed. */
    public function snapshotAllocation(): void
    {
        if ($this->kept_shipping_minor !== null || $this->origin_share_minor !== null) {
            return;
        }

        // Cash collection and delivery continue; no unsupported financial split is frozen.
        if ($this->allocationRequiresApproval()) {
            return;
        }

        $origin = $this->currentOriginShareMinor();
        $this->forceFill([
            'kept_shipping_minor' => (int) $this->sellerOrder?->shipping_fee_minor - $origin,
            'origin_share_minor' => $origin,
        ]);
    }

    private function currentOriginShareMinor(): int
    {
        return $this->isCrossProvider()
            ? Money::percentage((int) $this->sellerOrder?->shipping_fee_minor, self::ORIGIN_SHARE_PERCENT)
            : 0;
    }

    public function dueToZefanyaMinor(): ?int
    {
        $kept = $this->keptShippingMinor();

        return $kept === null ? null : $this->amount_minor - $kept;
    }

    public function allocationRequiresApproval(): bool
    {
        $parcel = $this->sellerOrder;
        if ($parcel === null) {
            return false;
        }
        $ids = app(ProviderAllocationService::class)->chain($parcel);
        $ids[] = (int) $this->logistics_provider_id;

        return count(array_unique(array_filter($ids))) >= 3;
    }

    public function allocationPending(): bool
    {
        return ($this->zefanya_received_at === null || $this->kept_shipping_minor === null) && $this->allocationRequiresApproval();
    }

    /** Money the rider collected but the company has not physically received. */
    public function outstandingShortageMinor(): int
    {
        return max(0, (int) $this->shortage_minor - (int) $this->shortage_recovered_minor);
    }

    public function hasOutstandingShortage(): bool
    {
        return $this->outstandingShortageMinor() > 0;
    }

    public function shortageRider(): BelongsTo
    {
        return $this->belongsTo(Rider::class, 'shortage_rider_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'logistics_provider_id');
    }

    public function originSharePayout(): BelongsTo
    {
        return $this->belongsTo(OriginSharePayout::class);
    }
}
