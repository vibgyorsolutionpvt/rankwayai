<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPublisherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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

        $post->update(['status' => 'publishing', 'failure_reason' => null]);
        $publisher->publish($post, $this->onlyPlatforms);
    }
}
