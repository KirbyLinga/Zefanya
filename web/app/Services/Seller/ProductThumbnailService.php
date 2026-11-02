<?php

namespace App\Services\Seller;

use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductThumbnailService
{
    /** Twice the widest product card, so cards stay sharp on high-density screens. */
    private const MAX_SIZE = 640;

    private const QUALITY = 80;

    /**
     * Store a card-sized WebP copy of a public image. A thumbnail is only an
     * optimization, so any failure returns null and cards use the original.
     */
    public function generate(string $path): ?string
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            $source = @imagecreatefromstring((string) $disk->get($path));
            if ($source === false) {
                return null;
            }

            $thumbnail = imagescale($source, min(self::MAX_SIZE, imagesx($source)));
            if ($thumbnail === false) {
                return null;
            }
            imagepalettetotruecolor($thumbnail);
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);

            ob_start();
            $encoded = imagewebp($thumbnail, null, self::QUALITY);
            $contents = (string) ob_get_clean();
            $target = $this->pathFor($path);

            return $encoded && $disk->put($target, $contents) ? $target : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** @param  array<int, string>  $paths  Original image paths. */
    public function delete(array $paths): void
    {
        Storage::disk('public')->delete(array_map($this->pathFor(...), $paths));
    }

    private function pathFor(string $path): string
    {
        return 'thumbnails/'.$path.'.webp';
    }
}
