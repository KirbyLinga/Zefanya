<?php

namespace App\Services\Admin;

use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Seller\Seller;
use App\Models\Shared\BuyerConductReport;
use App\Models\Shared\BuyerConductReview;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\BuyerConductReported;
use App\Notifications\Seller\BuyerReportUpdated;
use App\Support\ImageSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuyerConductReportService
{
    public function submit(SellerOrder $shipment, Seller $seller, string $reason, string $description, array $photos): BuyerConductReport
    {
        abort_unless(BuyerConductReport::available(), 503, 'Buyer reporting is awaiting the database update.');
        $stored = [];
        $created = false;
        try {
            $report = DB::transaction(function () use ($shipment, $seller, $reason, $description, $photos, &$stored, &$created): BuyerConductReport {
                $shipment = SellerOrder::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                abort_unless($seller->isApproved() && (int) $shipment->seller_id === (int) $seller->id, 404);
                $existing = BuyerConductReport::query()->where('seller_order_id', $shipment->id)->where('unresolved_key', 1)->first();
                if ($existing !== null) {
                    return $existing;
                }
                if (! array_key_exists($reason, BuyerConductReport::REASONS) || mb_strlen(trim($description)) < 10 || mb_strlen($description) > 2000 || count($photos) > 3) {
                    throw ValidationException::withMessages(['description' => 'Choose a reason and describe the concern in 10 to 2000 characters.']);
                }
                foreach ($photos as $index => $photo) {
                    $stored[] = ImageSanitizer::store($photo, 'buyer-reports/'.$shipment->id, 'local', 'evidence.'.$index);
                }
                $report = new BuyerConductReport;
                $report->forceFill([
                    'seller_order_id' => $shipment->id, 'seller_id' => $seller->id,
                    'buyer_id' => $shipment->order->buyer_id, 'reason' => $reason,
                    'description' => trim($description), 'evidence' => $stored,
                    'status' => 'open', 'unresolved_key' => 1,
                ])->save();
                // Reuse the staff database-notification channel and role boundaries.
                Admin::query()->whereIn('role', ['super_admin', 'admin', 'moderator'])->each(
                    fn (Admin $admin) => $admin->notify(new BuyerConductReported($report))
                );
                $created = true;

                return $report;
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        // Only after the report is committed, and only for a new report (not a duplicate submit).
        if ($created) {
            try {
                app(BuyerConductReviewService::class)->syncReport($report);
                $report->loadMissing('sellerOrder.order');
                $seller->notify(new BuyerReportUpdated($report, 'submitted'));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $report;
    }

    public function decide(BuyerConductReport $report, Admin $admin, string $decision, string $note): void
    {
        abort_unless(in_array($admin->role, ['super_admin', 'admin', 'moderator'], true), 403);
        if (BuyerConductReview::available()) {
            // One decision path: the conduct review records it and updates this report.
            throw ValidationException::withMessages(['decision' => 'Record this decision in the linked conduct review.']);
        }
        if (! array_key_exists($decision, BuyerConductReport::DECISIONS) || mb_strlen(trim($note)) < 10 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['decision_note' => 'Record a decision and a note in 10 to 2000 characters.']);
        }
        DB::transaction(function () use ($report, $admin, $decision, $note): void {
            $report = BuyerConductReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($report->status !== 'open') {
                return; // An existing decision is immutable; replay cannot overwrite it.
            }
            $report->forceFill(['status' => $decision, 'unresolved_key' => null,
                'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'decision_note' => trim($note)])->save();
            OperationsAuditLog::record($admin, 'buyer_report_reviewed', $report, 'Buyer report #'.$report->id,
                ['status' => 'open'], ['status' => $decision], trim($note));
        });
    }
}
