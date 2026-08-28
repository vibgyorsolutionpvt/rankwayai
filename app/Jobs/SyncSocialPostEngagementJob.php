<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use App\Services\Social\SocialPostAnalyticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSocialPostEngagementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [120, 600, 1800, 3600];

    public function __construct(public int $socialPostId) {}

    public function handle(SocialPostAnalyticsService $analytics): void
    {
        $post = SocialPost::query()->find($this->socialPostId);

        if (! $post || ! in_array($post->status, ['published'], true)) {
            return;
        }

        $analytics->syncPost($post);

        $stillPending = SocialPublishLog::query()
            ->where('social_post_id', $post->id)
            ->where('status', 'published')
            ->whereNotNull('external_post_id')
            ->whereNull('metrics_synced_at')
            ->exists();

        if ($stillPending) {
            throw new \RuntimeException('Engagement metrics not available yet — will retry.');
        }
    }
}
