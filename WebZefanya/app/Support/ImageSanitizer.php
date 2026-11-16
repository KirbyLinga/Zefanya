<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a public photo as a freshly encoded copy. Re-encoding drops EXIF
 * (GPS position, device, timestamps), ICC and any data appended to the file,
 * after first applying the EXIF rotation so the photo still looks upright.
 */
class ImageSanitizer
{
    public static function store(UploadedFile $file, string $directory, string $disk = 'public', string $field = 'image'): string
    {
        $pathname = $file->getPathname();
        $info = @getimagesize($pathname);
        $reject = fn () => ValidationException::withMessages([
            $field => 'This photo could not be processed. Use a JPG or PNG under '.config('uploads.max_side_px').' px per side.',
        ]);

        if ($info === false || $info[0] > config('uploads.max_side_px') || $info[1] > config('uploads.max_side_px')
            || $info[0] * $info[1] > config('uploads.max_pixels')) {
            throw $reject();
        }

        [$decode, $extension] = match ($info[2]) {
            IMAGETYPE_JPEG => ['imagecreatefromjpeg', 'jpg'],
            IMAGETYPE_PNG => ['imagecreatefrompng', 'png'],
            IMAGETYPE_WEBP => ['imagecreatefromwebp', 'webp'],
            default => throw $reject(),
        };

        $image = @$decode($pathname);
        if ($image === false) {
            throw $reject();
        }

        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $image = self::upright($image, self::jpegOrientation($pathname));
            }

            ob_start();
            match ($extension) {
                'jpg' => imagejpeg($image, null, 88),
                'png' => (function () use ($image): void {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image, null, 6);
                })(),
                'webp' => imagewebp($image, null, 85),
            };
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if ($bytes === '') {
            throw $reject();
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.'.$extension;
        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw $reject();
        }

        return $path;
    }

    /** EXIF orientation (1-8) read straight from the JPEG header; 1 when absent. Needs no exif extension. */
    public static function jpegOrientation(string $pathname): int
    {
        $handle = @fopen($pathname, 'rb');
        if ($handle === false) {
            return 1;
        }

        try {
            if (fread($handle, 2) !== "\xFF\xD8") {
                return 1;
            }
            while (! feof($handle)) {
                $marker = fread($handle, 4);
                if (strlen($marker) < 4 || $marker[0] !== "\xFF") {
                    return 1;
                }
                $length = unpack('n', substr($marker, 2, 2))[1];
                if ($marker[1] === "\xE1") { // APP1
                    $segment = fread($handle, min(max($length - 2, 0), 65535));

                    return self::tiffOrientation($segment);
                }
                if ($marker[1] === "\xDA") { // start of scan: no EXIF before it
                    return 1;
                }
                fseek($handle, $length - 2, SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }

        return 1;
    }

    private static function tiffOrientation(string $segment): int
    {
        if (substr($segment, 0, 6) !== "Exif\0\0" || strlen($segment) < 14) {
            return 1;
        }
        $tiff = substr($segment, 6);
        $little = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $at): int => unpack($little ? 'v' : 'n', substr($tiff, $at, 2) ?: "\0\0")[1];
        $u32 = fn (int $at): int => unpack($little ? 'V' : 'N', substr($tiff, $at, 4) ?: "\0\0\0\0")[1];

        $entries = $u16($offset = $u32(4));
        for ($i = 0; $i < min($entries, 64); $i++) {
            $entry = $offset + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [4], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }
}
