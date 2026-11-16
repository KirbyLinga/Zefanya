<?php

namespace App\Console\Commands;

use App\Models\Catalog\ProductImage;
use App\Services\Seller\ProductThumbnailService;
use Illuminate\Console\Command;

class GenerateProductThumbnailsCommand extends Command
{
    protected $signature = 'products:generate-thumbnails';

    protected $description = 'Generate card-sized thumbnails for product images that do not have one';

    public function handle(ProductThumbnailService $thumbnails): int
    {
        $generated = 0;
        $failed = 0;

        // One file can back several rows (a color's sizes), so work per path.
        ProductImage::query()->whereNull('thumbnail_path')->distinct()->orderBy('path')
            ->pluck('path')
            ->each(function (string $path) use ($thumbnails, &$generated, &$failed): void {
                $thumbnail = $thumbnails->generate($path);
                if ($thumbnail === null) {
                    $failed++;

                    return;
                }
                ProductImage::query()->where('path', $path)->update(['thumbnail_path' => $thumbnail]);
                $generated++;
            });

        $this->info("Generated {$generated} thumbnails; {$failed} images skipped.");

        return self::SUCCESS;
    }
}
