<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeedSocialEngagementSamplesCommand extends Command
{
    protected $signature = 'social:seed-engagement-samples
                            {workspace? : Workspace ID or slug (defaults to first workspace)}';

    protected $description = 'Mark two posts as published and insert sample engagement metrics in the DB';

    public function handle(): int
    {
        $workspace = $this->resolveWorkspace($this->argument('workspace'));
        if (! $workspace) {
            $this->error('No workspace found.');

            return self::FAILURE;
        }

        $samples = [
            [
                'title' => 'Engagement ask',
                'body' => 'Quick question for India: what is your #1 marketing headache this quarter?',
                'platforms' => ['instagram', 'facebook'],
                'metrics' => [
                    'instagram' => ['likes' => 198, 'comments' => 29, 'views' => 4200, 'shares' => 0, 'reposts' => 0],
                    'facebook' => ['likes' => 86, 'comments' => 12, 'views' => 0, 'shares' => 5, 'reposts' => 0],
                ],
            ],
            [
                'title' => 'Weekend offer',
                'body' => 'Limited-time travel packages from Noida — DM us for details.',
                'platforms' => ['facebook', 'threads'],
                'metrics' => [
                    'facebook' => ['likes' => 127, 'comments' => 18, 'views' => 0, 'shares' => 3, 'reposts' => 0],
                    'threads' => ['likes' => 45, 'comments' => 8, 'views' => 1100, 'shares' => 2, 'reposts' => 6],
                ],
            ],
        ];

        $syncedAt = now();

        foreach ($samples as $index => $sample) {
            $post = SocialPost::query()
                ->where('workspace_id', $workspace->id)
                ->where('title', $sample['title'])
                ->first();

            if (! $post) {
                $post = SocialPost::query()
                    ->where('workspace_id', $workspace->id)
                    ->orderByDesc('id')
                    ->skip($index)
                    ->first();
            }

            if (! $post) {
                $this->warn('Not enough posts in workspace #'.$workspace->id);

                continue;
            }

            $post->update([
                'title' => $sample['title'],
                'body' => $sample['body'],
                'platforms' => $sample['platforms'],
                'status' => 'published',
                'published_at' => $post->published_at ?? Carbon::now()->subDays(2 - $index),
                'failure_reason' => null,
            ]);

            foreach ($sample['platforms'] as $platform) {
                $metrics = $sample['metrics'][$platform];

                SocialPublishLog::query()->updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'social_post_id' => $post->id,
                        'platform' => $platform,
                    ],
                    [
                        'status' => 'published',
                        'external_post_id' => 'demo_'.$platform.'_'.$post->id,
                        'permalink' => 'https://example.com/'.$platform.'/'.$post->id,
                        'metrics' => $metrics,
                        'metrics_synced_at' => $syncedAt->copy()->subHours($index * 2),
                        'error' => null,
                    ],
                );
            }

            $this->line("Post #{$post->id} · {$sample['title']}");
        }

        $this->info("Sample engagement saved for workspace: {$workspace->name} (#{$workspace->id})");
        $this->line('Open SMM → Posts → Published (or All) to preview.');

        return self::SUCCESS;
    }

    private function resolveWorkspace(?string $needle): ?Workspace
    {
        if ($needle) {
            if (ctype_digit($needle)) {
                return Workspace::query()->find((int) $needle);
            }

            return Workspace::query()->where('slug', $needle)->first();
        }

        return Workspace::query()->orderBy('id')->first();
    }
}
