<?php

namespace App\Console\Commands;

use App\Models\Shared\BuyerConductReview;
use App\Services\Admin\BuyerConductReviewService;
use Illuminate\Console\Command;
use Throwable;

class ScanBuyerConductCommand extends Command
{
    protected $signature = 'buyers:scan-conduct';

    protected $description = 'Raise Admin conduct review alerts for Buyers (review only: no warning, restriction or Buyer notification)';

    public function handle(BuyerConductReviewService $reviews): int
    {
        if (! BuyerConductReview::available()) {
            $this->info('Conduct review is not installed yet; nothing scanned.');

            return self::SUCCESS;
        }

        try {
            $result = $reviews->scan();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Buyer conduct scan failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Alerts raised: {$result['created']}. Open alerts refreshed: {$result['refreshed']}.");

        return self::SUCCESS;
    }
}
