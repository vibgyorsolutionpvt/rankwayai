<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\PosterTemplateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $urls = [];
        foreach ($variants as $key => $path) {
            $urls[$key] = \Illuminate\Support\Facades\Storage::disk(config('media.disk', 'public'))->url($path);
        }

        $post->update(['poster_variants' => $urls ?: $variants]);
    }
}
