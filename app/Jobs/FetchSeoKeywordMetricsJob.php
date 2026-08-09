<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\Seo\SeoKeywordMetricsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchSeoKeywordMetricsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $workspaceId,
        public bool $force = false,
    ) {}

    public function handle(SeoKeywordMetricsService $metrics): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        try {
            $metrics->refresh($workspace, $this->force);
        } catch (\Throwable $e) {
            Log::warning('seo.metrics_job_failed', [
                'workspace_id' => $this->workspaceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
