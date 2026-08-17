<?php

namespace App\Services\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

/**
 * Generates QR PNGs — the modern replacement for the legacy phpqrcode + Qr::generate.
 * Encodes a token (e.g. a redeemable-stock QR code) into a PNG stored on the public
 * disk, returning its public URL for use as WhatsApp media.
 */
class QrCodeService
{
    /** Raw PNG bytes for the given text. ECC level L, scale 6 — matches the legacy output. */
    public function png(string $text): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::L,
            'scale' => 6,
            'outputBase64' => false,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($text);
    }

    /**
     * Render $text to qr/{filename}.png on the public disk (idempotent) and return its URL.
     * $filename defaults to the encoded text (the legacy convention: file named after the code).
     */
    public function store(string $text, ?string $filename = null): string
    {
        $path = 'qr/' . ($filename ?: $text) . '.png';

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $this->png($text));
        }

        // Request-host-derived URL (NOT Storage::url/APP_URL) so phones on the
        // LAN — and the live domain — always get a link they can actually load.
        return url('storage/' . $path);
    }
}
