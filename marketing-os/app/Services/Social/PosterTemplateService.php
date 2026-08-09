<?php

namespace App\Services\Social;

use App\Models\BrandKit;
use App\Models\MediaAsset;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PosterTemplateService
{
    /** @var array<string, array{w:int,h:int,label:string}> */
    public const SIZES = [
        'ig_feed' => ['w' => 1080, 'h' => 1080, 'label' => 'IG Feed'],
        'ig_story' => ['w' => 1080, 'h' => 1920, 'label' => 'IG/FB Story'],
        'link_share' => ['w' => 1200, 'h' => 630, 'label' => 'FB/LinkedIn Link'],
    ];

    /**
     * @return array<string, string> key => storage path
     */
    public function export(SocialPost $post, ?BrandKit $brand = null, ?MediaAsset $media = null): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return [];
        }

        $brand ??= $post->brandKit
            ?? ($post->brand_kit_id
                ? BrandKit::query()->find($post->brand_kit_id)
                : null)
            ?? $post->workspace?->resolveBrandKit();
        $media ??= $post->media;
        $diskName = config('media.disk', 'public');
        $disk = Storage::disk($diskName);
        $generated = [];

        $background = null;
        if ($media && str_starts_with((string) $media->mime_type, 'image/')) {
            $binary = $disk->get($media->path);
            if ($binary) {
                $tmp = tempnam(sys_get_temp_dir(), 'poster_bg_');
                file_put_contents($tmp, $binary);
                $background = @imagecreatefromstring(file_get_contents($tmp) ?: '');
                @unlink($tmp);
            }
        }

        $primary = $this->hexToRgb($brand?->primary_color ?: '#0F766E');
        $secondary = $this->hexToRgb($brand?->secondary_color ?: '#134E4A');
        $title = $post->title ?: Str::limit($post->body, 48, '');
        $cta = $brand?->default_cta_label ?: 'Learn more';
        $phone = $brand?->phone ?: '';

        foreach (self::SIZES as $key => $size) {
            $canvas = imagecreatetruecolor($size['w'], $size['h']);
            $bg = imagecolorallocate($canvas, $secondary[0], $secondary[1], $secondary[2]);
            imagefilledrectangle($canvas, 0, 0, $size['w'], $size['h'], $bg);

            if ($background) {
                $this->cover($canvas, $background, $size['w'], $size['h']);
                $overlay = imagecolorallocatealpha($canvas, 15, 23, 42, 70);
                imagefilledrectangle($canvas, 0, 0, $size['w'], $size['h'], $overlay);
            }

            $bandH = (int) round($size['h'] * 0.28);
            $bandY = $size['h'] - $bandH;
            $band = imagecolorallocatealpha($canvas, $primary[0], $primary[1], $primary[2], 30);
            imagefilledrectangle($canvas, 0, $bandY, $size['w'], $size['h'], $band);

            $white = imagecolorallocate($canvas, 255, 255, 255);
            $pad = (int) round($size['w'] * 0.06);
            $this->drawText($canvas, $title, $pad, $bandY + (int) round($bandH * 0.28), $white, 5);
            $this->drawText($canvas, $cta.($phone ? ' · '.$phone : ''), $pad, $bandY + (int) round($bandH * 0.62), $white, 3);

            $path = 'posters/'.$post->workspace_id.'/'.$post->id.'_'.$key.'.jpg';
            $out = tempnam(sys_get_temp_dir(), 'poster_');
            imagejpeg($canvas, $out, 86);
            imagedestroy($canvas);
            $disk->put($path, file_get_contents($out), 'public');
            @unlink($out);
            $generated[$key] = $path;
        }

        if ($background) {
            imagedestroy($background);
        }

        return $generated;
    }

    private function cover($canvas, $source, int $w, int $h): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($w / $sw, $h / $sh);
        $nw = (int) round($sw * $scale);
        $nh = (int) round($sh * $scale);
        $x = (int) round(($w - $nw) / 2);
        $y = (int) round(($h - $nh) / 2);
        imagecopyresampled($canvas, $source, $x, $y, 0, 0, $nw, $nh, $sw, $sh);
    }

    private function drawText($image, string $text, int $x, int $y, $color, int $font): void
    {
        imagestring($image, $font, $x, $y, substr($text, 0, 60), $color);
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
