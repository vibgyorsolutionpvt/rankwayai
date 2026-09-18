<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PublishDueSocialPostsCommand extends Command
{
    protected $signature = 'social:publish-due
                            {--all : Also force every status=publishing post (no age wait)}
                            {--drain : Drain database queue jobs after publishes}';

    protected $description = 'Publish due scheduled + stuck publishing posts synchronously (no queue worker required)';

    public function handle(): int
    {
        $published = 0;

        $due = SocialPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where(function ($query) {
                $query->where('requires_approval', false)
                    ->orWhereNotNull('approved_at');
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($due as $post) {
            $post->update(['status' => 'publishing', 'failure_reason' => null]);
            $this->publishOne($post->id, 'due scheduled');
            $published++;
        }

        $stuckQuery = SocialPost::query()
            ->where('status', 'publishing')
            ->orderBy('id')
            ->limit(200);

        if (! $this->option('all')) {
            $stuckQuery->where('updated_at', '<=', now()->subMinutes(1));
        }

        foreach ($stuckQuery->get() as $post) {
            $this->publishOne($post->id, 'stuck publishing');
            $published++;
        }

        if ($this->option('drain') && config('queue.default') === 'database') {
            $this->info('Draining queue…');
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 120,
                '--tries' => 3,
            ]);
            $this->line(trim(Artisan::output()));
        }

        $this->info('Processed '.$published.' post(s)');

        return self::SUCCESS;
    }

    private function publishOne(int $postId, string $label): void
    {
        try {
            PublishSocialPostJob::dispatchSync($postId);
            $status = SocialPost::query()->whereKey($postId)->value('status');
            $this->line("{$label} #{$postId} → {$status}");
        } catch (\Throwable $e) {
            $this->error("{$label} #{$postId} failed: ".$e->getMessage());
            report($e);
        }
    }
}
