<?php

namespace App\Observers;

use App\Models\Catalog\ProductImage;
use App\Services\Buyer\VisualCatalogIndex;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class VisualSearchImageObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(ProductImage $image): void
    {
        if ($image->wasRecentlyCreated || $image->wasChanged(['path', 'product_id', 'variant_id'])) {
            $this->mark($image);
        }
    }

    public function deleted(ProductImage $image): void
    {
        $this->mark($image);
    }

    private function mark(ProductImage $image): void
    {
        try {
            app(VisualCatalogIndex::class)->mark((int) $image->id);
        } catch (\Throwable) {
            // Derived-cache failures must never break product publishing. The scheduled scan repairs missed events.
            Log::warning('Visual search could not schedule an image refresh; scheduled reconciliation will retry.', ['image_id' => $image->id]);
        }
    }
}
