<?php

namespace App\Services\Seo;

use App\Models\SeoBlogPost;
use App\Models\SeoBlogShare;
use App\Models\Workspace;
use InvalidArgumentException;

class SeoBlogShareService
{
    /**
     * @return list<array{id: string, label: string, blurb: string}>
     */
    public function channels(): array
    {
        return config('seo.blog_share_channels', []);
    }

    public function shareUrl(SeoBlogPost $post, string $channel): string
    {
        if ($channel === 'copy') {
            return $post->url;
        }

        $channels = collect($this->channels())->keyBy('id');
        $def = $channels->get($channel);
        if (! $def || empty($def['template'])) {
            throw new InvalidArgumentException('Unknown share channel: '.$channel);
        }

        $plainTitle = $post->title ?: 'Worth a read';
        $excerpt = trim(strip_tags((string) $post->excerpt));
        if ($excerpt === '') {
            $excerpt = $plainTitle.' — quick read, feedback welcome.';
        }

        $body = $excerpt."\n\n".$post->url;

        $url = rawurlencode($post->url);
        $title = rawurlencode($plainTitle);
        $text = rawurlencode($body);

        return str_replace(
            ['{url}', '{title}', '{text}'],
            [$url, $title, $text],
            (string) $def['template']
        );
    }

    public function record(Workspace $workspace, SeoBlogPost $post, string $channel): SeoBlogShare
    {
        $shareUrl = $this->shareUrl($post, $channel);

        $share = SeoBlogShare::query()->create([
            'seo_blog_post_id' => $post->id,
            'workspace_id' => $workspace->id,
            'channel' => $channel,
            'share_url' => $shareUrl,
            'status' => 'opened',
        ]);

        $post->forceFill([
            'share_count' => (int) $post->share_count + 1,
            'last_shared_at' => now(),
        ])->save();

        return $share;
    }
}
