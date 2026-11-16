<?php

namespace App\Console\Commands;

use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Console\Command;
use Throwable;

class AutoApproveOverdueReturnsCommand extends Command
{
    protected $signature = 'returns:auto-approve-overdue';

    protected $description = 'Approve buyer return requests the Seller did not answer within three days';

    public function handle(ParcelReturnService $returns): int
    {
        $approved = 0;
        $failed = 0;

        ParcelReturn::query()
            ->whereNull('seller_decision')
            ->whereNotNull('seller_response_due_at')
            ->where('seller_response_due_at', '<=', now())
            ->select('id')
            ->chunkById(100, function ($records) use ($returns, &$approved, &$failed): void {
                foreach ($records as $record) {
                    try {
                        if ($returns->autoApproveOverdue((int) $record->id)) {
                            $approved++;
                        }
                    } catch (Throwable $exception) {
                        // An open parcel incident holds this one; the next run retries it.
                        $failed++;
                        report($exception);
                        $this->warn("Return #{$record->id} failed: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Approved: {$approved}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
