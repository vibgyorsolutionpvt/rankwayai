<?php

namespace App\Services\Studio;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class QrCodeService
{
    public function png(string $data, int $size = 240): string
    {
        $qr = new QrCode(
            data: $data,
            size: $size,
            margin: 10,
        );

        return (new PngWriter)->write($qr)->getString();
    }

    public function response(string $data, ?string $filename = null, bool $download = false, int $size = 240): Response
    {
        $png = $this->png($data, $size);
        $name = Str::slug($filename ?: 'qr').'.png';

        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ];

        if ($download) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$name.'"';
        } else {
            $headers['Content-Disposition'] = 'inline; filename="'.$name.'"';
        }

        return response($png, 200, $headers);
    }
}
