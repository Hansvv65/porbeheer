<?php
declare(strict_types=1);

namespace App\Qr;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RobThree\Auth\Providers\Qr\IQRCodeProvider;

final class QrSvgProvider implements IQRCodeProvider
{
    public function getQRCodeImage(string $qrtext, int $size): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($qrtext);
    }

    public function getMimeType(): string
    {
        return 'image/svg+xml';
    }
}