<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

class ImageVariantService
{
    /** @var array<string, int> */
    private array $sizes = [
        'thumb' => 320,
        'medium' => 960,
    ];

    /**
     * @return array<string, string> variant => path
     */
    public function generate(MediaAsset $asset): array
    {
        if (! str_starts_with((string) $asset->mime_type, 'image/')) {
            return [];
        }

        if (! function_exists('imagecreatetruecolor')) {
            return [];
        }

        $disk = Storage::disk($asset->disk);
        $binary = $disk->get($asset->path);

        if ($binary === null || $binary === false || $binary === '') {
            return [];
        }

        $temp = tempnam(sys_get_temp_dir(), 'atlas_media_');
        if ($temp === false) {
            return [];
        }

        file_put_contents($temp, $binary);
        $source = $this->load($temp, $asset->mime_type);
        @unlink($temp);

        if (! $source) {
            return [];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $generated = [];

        foreach ($this->sizes as $name => $maxWidth) {
            if ($width <= $maxWidth) {
                continue;
            }

            $ratio = $maxWidth / $width;
            $newW = (int) round($maxWidth);
            $newH = (int) round($height * $ratio);
            $canvas = imagecreatetruecolor($newW, $newH);

            if ($asset->mime_type === 'image/png' || $asset->mime_type === 'image/webp') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

            $variantPath = $this->variantPath($asset->path, $name);
            $out = tempnam(sys_get_temp_dir(), 'atlas_var_');
            $this->save($canvas, $out, $asset->mime_type);
            imagedestroy($canvas);

            $disk->put($variantPath, file_get_contents($out), 'public');
            @unlink($out);

            $generated[$name] = $variantPath;
        }

        imagedestroy($source);

        return $generated;
    }

    private function variantPath(string $originalPath, string $name): string
    {
        $dir = trim(dirname($originalPath), '.');
        $filename = pathinfo($originalPath, PATHINFO_FILENAME);
        $ext = pathinfo($originalPath, PATHINFO_EXTENSION);

        return ($dir ? $dir.'/' : '').$filename.'_'.$name.'.'.$ext;
    }

    private function load(string $path, ?string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function save($image, string $path, ?string $mime): void
    {
        match ($mime) {
            'image/png' => imagepng($image, $path, 7),
            'image/gif' => imagegif($image, $path),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, 80) : imagejpeg($image, $path, 82),
            default => imagejpeg($image, $path, 82),
        };
    }
}
