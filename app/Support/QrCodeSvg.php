<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A plain, content-agnostic QR-to-SVG renderer -- extracted from
 * TwoFactorService::qrCodeSvg() (which had zero 2FA-specific logic in the
 * method body) so the hotspot self-service link feature doesn't have to
 * reach into an unrelated-sounding service for a generic utility.
 */
class QrCodeSvg
{
    public static function render(string $data, int $size = 184): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString($data);
    }
}
