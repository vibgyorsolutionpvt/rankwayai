<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use Illuminate\Console\Command;

class PublishDueSocialPostsCommand extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Queue publish jobs for due scheduled social posts';

    public function handle(): int
    {
        $due = SocialPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where(function ($query) {
                $query->where('requires_approval', false)
                    ->orWhereNotNull('approved_at');
            })
            ->limit(100)
            ->get();

        foreach ($due as $post) {
            PublishSocialPostJob::dispatch($post->id);
            $this->line('Queued post #'.$post->id);
        }

        $this->info('Queued '.$due->count().' post(s)');

        return self::SUCCESS;
    }
}
