<?php

namespace App\Console\Commands;

use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Jobs\FetchSeoKeywordMetricsJob;
use App\Jobs\TrackSeoKeywordRanksJob;
use App\Models\SeoSite;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use Illuminate\Console\Command;

class RunDueSeoJobsCommand extends Command
{
    protected $signature = 'seo:run-due';

    protected $description = 'Queue due SEO crawls and keyword rank checks';

    public function handle(): int
    {
        $sites = SeoSite::query()
            ->where('crawl_frequency', '!=', 'manual')
            ->where(function ($q) {
                $q->whereNull('next_crawl_at')->orWhere('next_crawl_at', '<=', now());
            })
            ->whereIn('crawl_status', ['idle', 'failed'])
            ->limit(50)
            ->get();

        foreach ($sites as $site) {
            $site->update(['crawl_status' => 'queued']);
            CrawlAndAuditSeoSiteJob::dispatch($site->id);
            $this->line('Queued crawl #'.$site->id.' '.$site->domain);
        }

        Workspace::query()->whereHas('seoKeywords')->limit(50)->pluck('id')->each(function ($id) {
            TrackSeoKeywordRanksJob::dispatch($id);
        });

        $plans = app(PlanAccess::class);
        Workspace::query()->whereHas('seoKeywords')->limit(50)->get()->each(function (Workspace $workspace) use ($plans) {
            if ($plans->allows($workspace, 'seo_metrics')) {
                FetchSeoKeywordMetricsJob::dispatch($workspace->id);
            }
        });

        $this->info('Queued '.$sites->count().' crawl(s) + rank/metrics jobs');

        return self::SUCCESS;
    }
}
