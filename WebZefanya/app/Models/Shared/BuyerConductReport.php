<?php

namespace App\Models\Shared;

use App\Models\Seller\Seller;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BuyerConductReport extends Model
{
    public const REASONS = [
        'abusive_messages' => 'Abusive or threatening messages',
        'delivery_concern' => 'Delivery or receipt concern',
        'cancellation_concern' => 'Cancellation concern',
        'other' => 'Other concern',
    ];

    public const DECISIONS = [
        'closed_no_action' => 'Closed — no action needed',
        'closed_reviewed' => 'Closed — reviewed and recorded',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'reviewed_at' => 'datetime'];
    }

    public static function available(): bool
    {
        return RuntimeSchema::hasTable('buyer_conduct_reports');
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(BuyerConductReview::class, 'buyer_conduct_report_id');
    }

    /** What the reporting Seller sees. Never exposes the decision category or penalties. */
    public function sellerStatusLabel(): string
    {
        if ($this->status !== 'open') {
            return self::DECISIONS[$this->status] ?? 'Reviewed';
        }
        $inReview = BuyerConductReview::available() && $this->review?->status === 'in_review';

        return $inReview ? 'In Admin review' : 'Awaiting Admin review';
    }
}
