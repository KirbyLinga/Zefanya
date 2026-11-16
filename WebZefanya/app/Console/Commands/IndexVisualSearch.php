<?php

namespace App\Console\Commands;

use App\Exceptions\VisualSearchUnavailable;
use App\Services\Buyer\VisualSearchService;
use Illuminate\Console\Command;

class IndexVisualSearch extends Command
{
    protected $signature = 'visual-search:index {--batch=200 : Images per committed batch (1-1000)} {--max-batches=0 : Stop and resume after this many batches; 0 scans all} {--rebuild : Restart the scan and recompute features without clearing working entries}';

    protected $description = 'Cache local color/layout features for currently discoverable catalog photos';

    public function handle(VisualSearchService $search): int
    {
        try {
            $batch = (int) $this->option('batch');
            $maxBatches = (int) $this->option('max-batches');
            if ($batch < 1 || $batch > 1000 || $maxBatches < 0) {
                $this->error('Use --batch=1..1000 and --max-batches=0 or a positive integer.');

                return self::FAILURE;
            }
            $result = $search->rebuild($batch, $maxBatches, (bool) $this->option('rebuild'));
        } catch (VisualSearchUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\PDOException) {
            $this->error('The derived image cache could not be updated. Check its disk space and permissions; existing search entries were retained.');

            return self::FAILURE;
        }
        $this->info("Eligible {$result['eligible']}; cached {$result['cached']}; refreshed {$result['indexed']}; reused {$result['reused']}; duplicate features {$result['deduplicated']}; skipped {$result['skipped']}; cursor {$result['cursor']}; {$result['seconds']}s.");

        return self::SUCCESS;
    }
}
