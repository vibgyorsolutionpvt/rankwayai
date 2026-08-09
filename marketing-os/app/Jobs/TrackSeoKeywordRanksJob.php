<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\Seo\SeoRankTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TrackSeoKeywordRanksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workspaceId) {}

    public function handle(SeoRankTracker $tracker): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace) {
            $tracker->track($workspace);
        }
    }
}
