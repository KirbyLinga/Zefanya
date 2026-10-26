<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Server-side SVG QR for printable seller waybills.
 *
 * Encodes only the opaque tracking reference — never buyer PII, COD amounts,
 * or auth tokens. Output is deterministic for a given tracking string.
 */
class WaybillQrCode
{
    /** Printed module canvas size (px). Large enough for handheld scanners. */
    private const SIZE = 168;

    /** Quiet-zone modules around the symbol (QR spec minimum is 4). */
    private const MARGIN = 4;

    /**
     * Build a self-contained SVG for $trackingNumber, or null when the
     * reference is missing/blank (never invent a fake identifier).
     */
    public function svg(?string $trackingNumber): ?string
    {
        $payload = trim((string) $trackingNumber);

        if ($payload === '') {
            return null;
        }

        $renderer = new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($payload);
    }

    /**
     * The exact string encoded into the QR — kept as a named method so tests
     * and the waybill stay aligned on the opaque-reference contract.
     */
    public function payload(?string $trackingNumber): ?string
    {
        $payload = trim((string) $trackingNumber);

        return $payload === '' ? null : $payload;
    }
}
