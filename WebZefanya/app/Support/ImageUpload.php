<?php

namespace App\Support;

use Closure;

/** Shared size limits for photo uploads, so a small file cannot expand into a memory-exhausting bitmap. */
class ImageUpload
{
    /** @return array<int, string|Closure> Append to an existing 'file', 'image', 'mimes:…' rule. */
    public static function dimensionRules(): array
    {
        $side = (int) config('uploads.max_side_px');

        return ['dimensions:max_width='.$side.',max_height='.$side, self::pixelCap()];
    }

    public static function pixelCap(): Closure
    {
        return function (string $attribute, mixed $file, Closure $fail): void {
            $size = is_object($file) && method_exists($file, 'getPathname') ? @getimagesize($file->getPathname()) : false;
            if ($size === false) {
                return; // The image/mimes rules own the "not an image" message.
            }
            $max = (int) config('uploads.max_pixels');
            if ($size[0] * $size[1] > $max) {
                $fail('The image is too large. Use one under '.round($max / 1_000_000).' megapixels.');
            }
        };
    }
}
