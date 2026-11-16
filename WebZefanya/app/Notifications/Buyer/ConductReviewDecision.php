<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\BuyerConductReview;
use Illuminate\Notifications\Notification;

/**
 * Sent to the Buyer only after an Admin records an Advisory, Formal warning or
 * account restriction. Factual and non-accusatory; never sent for an alert alone.
 */
class ConductReviewDecision extends Notification
{
    public function __construct(public BuyerConductReview $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $review = $this->review;
        $title = match ($review->decision) {
            'advisory' => 'Account advisory',
            'formal_warning' => 'Formal account warning',
            'account_restriction' => 'Account restricted',
            default => 'Account notice',
        };
        $topic = match ($review->category) {
            BuyerConductReview::CATEGORY_CANCELLATIONS => 'the number of orders cancelled from your account in a short period',
            BuyerConductReview::CATEGORY_RETURNS => 'recent return requests where, after Admin review, the original decision stood',
            default => 'a concern raised about one of your orders',
        };
        $decision = match ($review->decision) {
            'advisory' => 'This is an advisory only.',
            'formal_warning' => 'This is a formal warning recorded on your account.',
            'account_restriction' => 'Your account has been suspended under the existing account restriction process.',
            default => '',
        };
        $status = $review->decision === 'account_restriction'
            ? 'Current account status: suspended. The suspension has no automatic end date and stays in place until Zefanya reactivates the account after review.'
            : 'Current account status: active. No restriction has been applied, and your ordering, cancellation and return rights are unchanged.';
        $reason = filled($review->decision_reason) ? ' Reason: '.$review->decision_reason : '';

        return [
            'type' => 'conduct_review_decision',
            'title' => $title,
            'decision' => $review->decision,
            'review_reference' => $review->reference(),
            'account_status' => $review->account_status_after,
            'message' => "Zefanya reviewed {$topic}. {$decision}{$reason} {$status} "
                ."If you think this is mistaken or would like it reconsidered, contact Zefanya support through the Help page and quote {$review->reference()}.",
        ];
    }
}
