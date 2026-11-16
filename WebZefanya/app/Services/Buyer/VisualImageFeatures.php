<?php

namespace App\Services\Buyer;

use App\Exceptions\VisualSearchUnavailable;
use App\Support\ImageSanitizer;
use GdImage;
use Illuminate\Validation\ValidationException;

/** Bounded, non-semantic image descriptors. No upload bytes are retained. */
class VisualImageFeatures
{
    public const VERSION = 'structure-color-v2';

    public function ensureAvailable(): void
    {
        if (! config('visual-search.enabled') || ! extension_loaded('gd')
            || ! function_exists('imagecreatefromwebp')) {
            throw new VisualSearchUnavailable('Image search is temporarily unavailable. Please try again later.');
        }
    }

    /** @return array{color: array<int, float>, center: array<int, float>, layout: list<float>, structure: list<float>} */
    public function describe(string $path): array
    {
        $this->ensureAvailable();
        $invalid = fn () => ValidationException::withMessages([
            'image' => 'This image could not be processed. Use a JPEG, PNG or WebP up to 5 MB and 12 megapixels.',
        ]);
        $size = @filesize($path);
        $info = @getimagesize($path);
        if ($size === false || $size > 5 * 1024 * 1024 || $info === false
            || $info[0] < 1 || $info[1] < 1
            || max($info[0], $info[1]) > config('visual-search.max_side')
            || config('visual-search.max_pixels') < $info[0] * $info[1]) {
            throw $invalid();
        }
        $decode = match ($info[2]) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => throw $invalid(),
        };
        $image = @$decode($path);
        if (! $image instanceof GdImage) {
            throw $invalid();
        }
        $small = null;
        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                try {
                    $orientation = ImageSanitizer::jpegOrientation($path);
                } catch (\ValueError|\ErrorException) {
                    throw $invalid();
                }
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated instanceof GdImage) {
                        imagedestroy($image);
                        $image = $rotated;
                    }
                }
            }
            $small = imagecreatetruecolor(64, 64);
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            imagealphablending($small, true);
            imagecopyresampled($small, $image, 0, 0, 0, 0, 64, 64, imagesx($image), imagesy($image));
            $color = $center = [];
            $layout = array_fill(0, 48, 0.0);
            for ($y = 0; $y < 64; $y++) {
                for ($x = 0; $x < 64; $x++) {
                    $rgb = imagecolorat($small, $x, $y);
                    $r = ($rgb >> 16) & 255;
                    $g = ($rgb >> 8) & 255;
                    $b = $rgb & 255;
                    $bin = (intdiv($r, 32) * 64) + (intdiv($g, 32) * 8) + intdiv($b, 32);
                    $color[$bin] = ($color[$bin] ?? 0) + 1;
                    if ($x >= 12 && $x < 52 && $y >= 12 && $y < 52) {
                        $center[$bin] = ($center[$bin] ?? 0) + 1;
                    }
                    $cell = (intdiv($y, 16) * 4 + intdiv($x, 16)) * 3;
                    $layout[$cell] += $r / (255 * 256);
                    $layout[$cell + 1] += $g / (255 * 256);
                    $layout[$cell + 2] += $b / (255 * 256);
                }
            }

            return ['color' => $this->normalize($color), 'center' => $this->normalize($center),
                'layout' => array_map(fn ($value) => round($value, 6), $layout),
                'structure' => $this->structure($small)];
        } finally {
            imagedestroy($image);
            if ($small instanceof GdImage) {
                imagedestroy($small);
            }
        }
    }

    /** Square-root histograms are unit vectors; their dot product is cosine similarity. */
    private function normalize(array $histogram): array
    {
        $total = array_sum($histogram);

        return array_map(fn ($count) => round(sqrt($count / $total), 6), $histogram);
    }

    /** Spatial, unsigned gradient directions: contrast/brightness independent, not object recognition. */
    private function structure(GdImage $image): array
    {
        $gray = [];
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $gray[$y][$x] = 0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255);
            }
        }
        $bins = array_fill(0, 128, 0.0);
        for ($y = 1; $y < 63; $y++) {
            for ($x = 1; $x < 63; $x++) {
                $dx = $gray[$y][$x + 1] - $gray[$y][$x - 1];
                $dy = $gray[$y + 1][$x] - $gray[$y - 1][$x];
                $magnitude = sqrt($dx * $dx + $dy * $dy);
                // Suppress JPEG/background noise, without interpreting any color as a category.
                if ($magnitude < 12) {
                    continue;
                }
                $angle = fmod(atan2($dy, $dx) + M_PI, M_PI) / M_PI * 8;
                $bin = (int) floor($angle);
                $fraction = $angle - $bin;
                $cell = (intdiv($y, 16) * 4 + intdiv($x, 16)) * 8;
                $bins[$cell + $bin % 8] += $magnitude * (1 - $fraction);
                $bins[$cell + ($bin + 1) % 8] += $magnitude * $fraction;
            }
        }
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $bins)));

        return array_map(fn ($v) => $norm > 0 ? round($v / $norm, 6) : 0.0, $bins);
    }

    public function structuralSimilarity(array $a, array $b): float
    {
        $x = $a['structure'];
        $y = $b['structure'];
        if (array_sum($x) === 0.0 || array_sum($y) === 0.0) {
            // A featureless image carries no positive shape evidence.
            return 0.0;
        }

        return min(1.0, array_sum(array_map(fn ($v, $w) => $v * $w, $x, $y)));
    }

    public function similarity(array $a, array $b): float
    {
        $dot = static function (array $x, array $y): float {
            $score = 0.0;
            foreach ($x as $bin => $value) {
                $score += $value * ($y[$bin] ?? 0);
            }

            return min(1.0, $score);
        };
        $distance = 0.0;
        foreach ($a['layout'] as $i => $value) {
            $distance += ($value - $b['layout'][$i]) ** 2;
        }

        $appearance = 0.35 * $dot($a['color'], $b['color']) + 0.45 * $dot($a['center'], $b['center'])
            + 0.20 * max(0, 1 - sqrt($distance / 48));

        return 0.80 * $this->structuralSimilarity($a, $b) + 0.20 * $appearance;
    }
}
