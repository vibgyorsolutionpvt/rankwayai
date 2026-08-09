<?php

namespace App\Services\Seo;

use App\Models\SeoKeyword;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Contracts\KeywordMetricsProvider;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Services\Seo\Providers\DataForSeoKeywordMetricsProvider;
use App\Services\Seo\Providers\NullKeywordMetricsProvider;
use Illuminate\Support\Collection;
use RuntimeException;

class SeoKeywordMetricsService
{
    public function __construct(
        private PlanAccess $plans,
        private SeoApiUsageLogger $usage,
    ) {}

    public function provider(): KeywordMetricsProvider
    {
        if (config('seo.providers.metrics') === 'dataforseo' && DataForSeoClient::configured()) {
            return app(DataForSeoKeywordMetricsProvider::class);
        }

        return app(NullKeywordMetricsProvider::class);
    }

    public function canFetchLive(Workspace $workspace): bool
    {
        return $this->plans->allows($workspace, 'seo_metrics')
            && $this->provider()->configured();
    }

    /**
     * @return array{updated:int, skipped:int, message:string}
     */
    public function refresh(Workspace $workspace, bool $force = false): array
    {
        if (! $this->plans->allows($workspace, 'seo_metrics')) {
            throw new RuntimeException($this->plans->denyMessage('seo_metrics'));
        }

        $provider = $this->provider();
        if (! $provider->configured()) {
            throw new RuntimeException('DataForSEO is not configured. Set DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD.');
        }

        $cacheDays = max(1, (int) config('seo.metrics_cache_days', 7));
        $batchSize = max(1, (int) config('seo.metrics_batch_size', 50));

        $query = $workspace->seoKeywords()->orderBy('id');
        if (! $force) {
            $query->where(function ($q) use ($cacheDays) {
                $q->whereNull('metrics_fetched_at')
                    ->orWhere('metrics_fetched_at', '<', now()->subDays($cacheDays));
            });
        }

        /** @var Collection<int, SeoKeyword> $keywords */
        $keywords = $query->limit($batchSize)->get();
        if ($keywords->isEmpty()) {
            return ['updated' => 0, 'skipped' => 0, 'message' => 'All keyword metrics are fresh (cache).'];
        }

        $location = (string) config('seo.default_location', 'India');
        $language = (string) config('seo.default_language', 'en');

        // Group by location so local keywords use their city when set.
        $groups = $keywords->groupBy(fn (SeoKeyword $kw) => $kw->location ?: $location);
        $updated = 0;

        foreach ($groups as $loc => $group) {
            $metrics = $provider->fetch(
                $group->pluck('keyword')->all(),
                (string) $loc,
                $language
            );
            $byKeyword = collect($metrics)->keyBy(fn ($m) => mb_strtolower($m->keyword));

            foreach ($group as $keyword) {
                $metric = $byKeyword->get(mb_strtolower($keyword->keyword));
                if (! $metric) {
                    $keyword->update(['metrics_fetched_at' => now(), 'metrics_provider' => $provider->name()]);
                    continue;
                }
                $keyword->update([
                    'search_volume' => $metric->searchVolume,
                    'keyword_difficulty' => $metric->difficulty,
                    'cpc' => $metric->cpc,
                    'competition' => $metric->competition,
                    'metrics_provider' => $metric->provider,
                    'metrics_fetched_at' => now(),
                ]);
                $updated++;
            }

            $this->usage->log(
                $workspace,
                $provider->name(),
                'keyword_metrics',
                $group->count(),
                null,
                ['location' => $loc, 'keywords' => $group->pluck('keyword')->values()->all()]
            );
        }

        return [
            'updated' => $updated,
            'skipped' => 0,
            'message' => "Updated metrics for {$updated} keyword(s) via {$provider->name()}.",
        ];
    }
}
