<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPublisherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  list<string>|null  $onlyPlatforms */
    public function __construct(
        public int $socialPostId,
        public ?array $onlyPlatforms = null,
    ) {}

    public function handle(SocialPublisherService $publisher): void
    {
        $post = SocialPost::query()->find($this->socialPostId);

        if (! $post) {
            return;
        }

        if ($post->requires_approval && ! $post->approved_at) {
            $post->update([
                'status' => 'failed',
                'failure_reason' => 'Waiting for approval',
            ]);

            return;
        }

        // Already done (e.g. terminating sync + late queue worker) — don't double-post.
        if ($post->status === 'published' && empty($this->onlyPlatforms)) {
            return;
        }

        $post->update(['status' => 'publishing', 'failure_reason' => null]);
        $publisher->publish($post, $this->onlyPlatforms);
    }

    /**
     * Fast HTTP submit, then publish immediately after the response is sent.
     *
     * Hostinger note: never push a "drain queue" closure onto the database queue —
     * with no queue:work daemon that deadlocks and posts stay PUBLISHING forever.
     * We run the job sync in app terminating() (after the browser already got redirect).
     *
     * @param  list<string>|null  $onlyPlatforms
     */
    public static function queueAndProcess(int $socialPostId, ?array $onlyPlatforms = null): void
    {
        app()->terminating(function () use ($socialPostId, $onlyPlatforms) {
            try {
                $fresh = SocialPost::query()->find($socialPostId);
                if (! $fresh) {
                    return;
                }
                if ($fresh->status === 'published' && empty($onlyPlatforms)) {
                    return;
                }
                if ($fresh->status === 'failed') {
                    return;
                }

                static::dispatchSync($socialPostId, $onlyPlatforms);
            } catch (\Throwable $e) {
                Log::error('PublishSocialPostJob terminating publish failed', [
                    'post_id' => $socialPostId,
                    'error' => $e->getMessage(),
                ]);
                report($e);

                SocialPost::query()->whereKey($socialPostId)->where('status', 'publishing')->update([
                    'status' => 'failed',
                    'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        });
    }
}