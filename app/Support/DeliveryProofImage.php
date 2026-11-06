<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof photos are private images. The type comes from the file bytes, never
 * from the rider's browser, and only JPEG, PNG, and WebP are stored or served.
 */
class DeliveryProofImage
{
    /** @var array<string, string> */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function storeMime(UploadedFile $photo): string
    {
        $mime = self::normalize((new \finfo(FILEINFO_MIME_TYPE))->file($photo->getPathname()));

        if (! isset(self::TYPES[$mime])) {
            throw ValidationException::withMessages([
                'proof_photo' => 'Photos must be JPG, PNG, or WebP files.',
            ]);
        }

        return $mime;
    }

    public static function response(string $path): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $mime = self::normalize($disk->mimeType($path));

        if (! isset(self::TYPES[$mime])) {
            abort(415, 'This proof is not a supported image.');
        }

        $filename = 'delivery-proof.'.self::TYPES[$mime];

        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private static function normalize(mixed $mime): string
    {
        $mime = is_string($mime) ? strtolower(trim(strtok($mime, ';') ?: '')) : '';

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }
}
