<?php

namespace App\Jobs;

use App\Models\SeoSite;
use App\Services\Seo\SeoAuditEngine;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoTaskGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CrawlAndAuditSeoSiteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $seoSiteId) {}

    public function handle(
        SeoCrawlerService $crawler,
        SeoAuditEngine $audit,
        SeoTaskGenerator $tasks
    ): void {
        $site = SeoSite::query()->with('workspace')->find($this->seoSiteId);
        if (! $site) {
            return;
        }

        try {
            $pages = $crawler->crawl($site);
            $audit->audit($site->fresh());
            if ($site->workspace && count($pages) > 0) {
                $tasks->generate($site->workspace, $site->fresh());
                $tasks->generateAiSuggestions($site->workspace, $site);
            }
        } catch (\Throwable $e) {
            $site->update([
                'crawl_status' => 'failed',
                'last_crawl_error' => \Illuminate\Support\Str::limit($e->getMessage(), 1000),
            ]);
            throw $e;
        }
    }
}
