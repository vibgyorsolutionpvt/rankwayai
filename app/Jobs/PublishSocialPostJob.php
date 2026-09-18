<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPublisherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

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

        $post->update(['status' => 'publishing', 'failure_reason' => null]);
        $publisher->publish($post, $this->onlyPlatforms);
    }

    /**
     * 1) Queue the job (HTTP returns immediately with status=publishing)
     * 2) After the response is sent, drain the queue so Meta publish finishes
     *    without waiting for a long-running queue:work / cron.
     *
     * @param  list<string>|null  $onlyPlatforms
     */
    public static function queueAndProcess(int $socialPostId, ?array $onlyPlatforms = null): void
    {
        static::dispatch($socialPostId, $onlyPlatforms);

        // Hostinger / no daemon: process queued jobs right after the browser gets the redirect.
        dispatch(function () {
            if (config('queue.default') === 'sync') {
                return;
            }

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 90,
                '--tries' => 3,
                '--max-jobs' => 10,
            ]);
        })->afterResponse();
    }
}
