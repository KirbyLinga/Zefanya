<?php

namespace App\Models\Shared;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Admin conduct review case. Creating or updating a case never changes the
 * Buyer account, COD eligibility, orders, returns or money; only a recorded
 * Admin decision can, and only through the existing account-suspension path.
 */
class BuyerConductReview extends Model
{
    public const CATEGORY_CANCELLATIONS = 'cancellations';

    public const CATEGORY_RETURNS = 'returns';

    public const CATEGORY_SELLER_REPORT = 'seller_report';

    public const CATEGORIES = [
        self::CATEGORY_CANCELLATIONS => 'Repeated cancellations',
        self::CATEGORY_RETURNS => 'Return outcomes',
        self::CATEGORY_SELLER_REPORT => 'Seller report',
    ];

    public const STATUSES = [
        'pending' => 'Pending review',
        'in_review' => 'In review',
        'resolved' => 'Resolved',
    ];

    public const DECISIONS = [
        'no_action' => 'No action',
        'advisory' => 'Advisory',
        'formal_warning' => 'Formal warning',
        'account_restriction' => 'Existing account restriction (suspend account)',
        'closed_duplicate' => 'Close as duplicate',
        'closed_unsubstantiated' => 'Close as unsubstantiated',
    ];

    /** Decisions that must carry a written justification. */
    public const JUSTIFIED_DECISIONS = ['formal_warning', 'account_restriction'];

    /** Decisions the Buyer is told about. No action and closures are not sent. */
    public const BUYER_NOTIFIED_DECISIONS = ['advisory', 'formal_warning', 'account_restriction'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'window_started_at' => 'datetime',
            'window_ended_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
            'buyer_notified_at' => 'datetime',
            'seller_review_notified_at' => 'datetime',
            'seller_resolved_notified_at' => 'datetime',
        ];
    }

    public static function available(): bool
    {
        return BuyerConductReport::available() && RuntimeSchema::hasTable('buyer_conduct_reviews');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(BuyerConductReport::class, 'buyer_conduct_report_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function reference(): string
    {
        return 'CR-'.$this->id;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Conduct review';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Review';
    }

    public function decisionLabel(): ?string
    {
        return $this->decision === null ? null : (self::DECISIONS[$this->decision] ?? 'Decision recorded');
    }

    /** The Seller-facing report status this decision maps to. Penalty details are never shown to Sellers. */
    public static function reportStatusFor(string $decision): string
    {
        return in_array($decision, ['no_action', 'closed_duplicate', 'closed_unsubstantiated'], true)
            ? 'closed_no_action'
            : 'closed_reviewed';
    }
}
