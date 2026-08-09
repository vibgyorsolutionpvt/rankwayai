<?php

namespace App\Services\Seo;

use App\Models\SeoCompetitor;
use App\Models\SeoKeyword;
use App\Models\SeoKeywordRank;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Contracts\SerpRankProvider;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Services\Seo\Providers\DataForSeoSerpRankProvider;
use App\Services\Seo\Providers\StubSerpRankProvider;
use Illuminate\Support\Collection;

class SeoRankTracker
{
    public function __construct(
        private PlanAccess $plans,
        private SeoApiUsageLogger $usage,
    ) {}

    public function resolveProvider(Workspace $workspace): SerpRankProvider
    {
        $driver = (string) config('seo.providers.ranks', 'auto');

        $wantLive = match ($driver) {
            'dataforseo' => true,
            'stub' => false,
            default => DataForSeoClient::configured(),
        };

        if ($wantLive
            && DataForSeoClient::configured()
            && $this->plans->allows($workspace, 'seo_metrics')
        ) {
            return app(DataForSeoSerpRankProvider::class);
        }

        return app(StubSerpRankProvider::class);
    }

    /**
     * @return Collection<int, SeoKeyword>
     */
    public function track(Workspace $workspace): Collection
    {
        $keywords = $workspace->seoKeywords()->get();
        if ($keywords->isEmpty()) {
            return $keywords;
        }

        $provider = $this->resolveProvider($workspace);
        $targetDomain = $workspace->seoSites()->latest()->value('domain') ?: 'example.com';
        $defaultLocation = (string) config('seo.default_location', 'India');
        $language = (string) config('seo.default_language', 'en');

        foreach ($keywords as $keyword) {
            $previous = $keyword->position;
            $location = $keyword->location ?: $defaultLocation;

            $result = $provider->rankFor(
                $keyword->keyword,
                $targetDomain,
                $location,
                $language,
                (bool) $keyword->is_local
            );

            if ($provider->name() === 'stub') {
                $delta = random_int(-3, 3);
                $next = $previous === null
                    ? (int) ($result->organicPosition ?? random_int(5, 40))
                    : max(1, min(100, $previous + $delta));
                $localPack = $keyword->is_local
                    ? ($result->localPackPosition ?? random_int(1, 3))
                    : null;
            } else {
                // Live API: keep previous rank if domain not found in top results (null).
                $next = $result->organicPosition ?? $previous;
                $localPack = $result->localPackPosition;
            }

            $change = ($previous === null || $next === null) ? 0 : ($previous - $next);

            SeoKeywordRank::query()->create([
                'workspace_id' => $workspace->id,
                'seo_keyword_id' => $keyword->id,
                'position' => $next,
                'checked_at' => now(),
            ]);

            $keyword->update([
                'position' => $next,
                'position_change' => $change,
                'local_pack_position' => $localPack,
                'rank_provider' => $provider->name(),
                'last_checked_at' => now(),
            ]);

            if ($provider->name() !== 'stub') {
                $this->usage->log(
                    $workspace,
                    $provider->name(),
                    'serp_rank',
                    1,
                    isset($result->meta['cost']) ? (float) $result->meta['cost'] : null,
                    [
                        'keyword' => $keyword->keyword,
                        'domain' => $targetDomain,
                        'organic' => $result->organicPosition,
                        'local_pack' => $result->localPackPosition,
                    ]
                );
            }
        }

        return $keywords->fresh();
    }

    public function seedCompetitorStub(Workspace $workspace, string $domain): SeoCompetitor
    {
        $keywords = $workspace->seoKeywords()->limit(5)->pluck('keyword')->all();

        return SeoCompetitor::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'domain' => $domain],
            [
                'overlap_score' => random_int(20, 80),
                'shared_keywords' => $keywords,
            ]
        );
    }
}
