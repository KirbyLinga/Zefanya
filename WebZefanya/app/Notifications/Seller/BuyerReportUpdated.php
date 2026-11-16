<?php

namespace App\Notifications\Seller;

use App\Models\Shared\BuyerConductReport;
use Illuminate\Notifications\Notification;

/**
 * Status of a Seller's own Buyer report: submitted, in review, resolved.
 * Never includes the Buyer's account history, other cases or penalty details.
 */
class BuyerReportUpdated extends Notification
{
    /** @param  'submitted'|'in_review'|'resolved'  $stage */
    public function __construct(public BuyerConductReport $report, public string $stage) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $report = $this->report;
        $reference = $report->sellerOrder?->order?->reference ?? 'shipment #'.$report->seller_order_id;
        $outcome = BuyerConductReport::DECISIONS[$report->status] ?? 'Reviewed';

        [$title, $message] = match ($this->stage) {
            'submitted' => ['Buyer report submitted',
                "Your report #{$report->id} for order {$reference} was submitted. Admin will review it. A report does not apply a penalty by itself."],
            'in_review' => ['Buyer report in review',
                "Admin has started reviewing your report #{$report->id} for order {$reference}."],
            default => ['Buyer report reviewed',
                "Admin finished reviewing your report #{$report->id} for order {$reference}: {$outcome}. Any account action stays private between Zefanya and the Buyer."],
        };

        return [
            'type' => 'buyer_report_'.$this->stage,
            'title' => $title,
            'seller_order_id' => $report->seller_order_id,
            'buyer_conduct_report_id' => $report->id,
            'order_reference' => $reference,
            'message' => $message,
        ];
    }
}
