<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Services\Social\PosterTemplateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class GeneratePosterVariantsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $socialPostId) {}

    public function handle(PosterTemplateService $posters): void
    {
        $post = SocialPost::query()->with(['media', 'brandKit', 'workspace.brandKits'])->find($this->socialPostId);

        if (! $post) {
            return;
        }

        $variants = $posters->export($post);
        $diskName = config('media.disk', 'public');
        $disk = Storage::disk($diskName);
        $urls = [];
        foreach ($variants as $key => $path) {
            $urls[$key] = $disk->url($path);
        }

        $post->update(['poster_variants' => $urls ?: $variants]);

        if ($post->media_asset_id || $variants === []) {
            return;
        }

        $path = $variants['ig_feed'] ?? $variants['link_share'] ?? reset($variants);
        if (! is_string($path) || $path === '') {
            return;
        }

        $asset = MediaAsset::query()->create([
            'workspace_id' => $post->workspace_id,
            'uploaded_by' => $post->created_by,
            'disk' => $diskName,
            'path' => $path,
            'original_name' => 'poster-'.$post->id.'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => $disk->exists($path) ? (int) $disk->size($path) : 0,
            'folder' => 'AI Posters',
            'status' => 'ready',
            'variants' => $variants,
        ]);

        $post->update(['media_asset_id' => $asset->id]);
    }
}
