<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Social\SocialPostAnalyticsService;
use Illuminate\Console\Command;

class SyncSocialPostMetricsCommand extends Command
{
    protected $signature = 'social:sync-metrics {--workspace= : Workspace ID to sync}';

    protected $description = 'Sync likes, comments, and views for published Meta posts';

    public function handle(SocialPostAnalyticsService $analytics): int
    {
        $workspaceId = $this->option('workspace');

        if ($workspaceId) {
            $workspace = Workspace::query()->find((int) $workspaceId);
            if (! $workspace) {
                $this->error('Workspace not found.');

                return self::FAILURE;
            }

            $count = $analytics->syncWorkspace($workspace, 100);
            $this->info("Synced {$count} publish log(s) for workspace {$workspace->id}.");

            return self::SUCCESS;
        }

        $total = 0;
        Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($analytics, &$total) {
            foreach ($workspaces as $workspace) {
                $total += $analytics->syncWorkspace($workspace, 25);
            }
        });

        $this->info("Synced {$total} publish log(s) across workspaces.");

        return self::SUCCESS;
    }
}
