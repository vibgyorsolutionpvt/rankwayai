<?php

namespace App\Services\Rankway;

use App\Models\RankwayDomain;
use App\Models\RankwayDomainMetric;
use App\Models\RankwayDomainRankHistory;
use App\Models\SeoSite;
use App\Services\Seo\Providers\DataForSeoBacklinkProvider;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Support\DomainNormalizer;
use Illuminate\Support\Facades\Log;

class RankwayDomainAnalyzer
{
    public function __construct(
        private RankwayDomainProbe $probe,
        private RankwayScoringEngine $scoring,
        private RankwayRankEngine $ranks,
        private RankwayAuthorityBenchmarks $benchmarks,
    ) {}

    public function findOrCreate(string $rawDomain): RankwayDomain
    {
        $domain = DomainNormalizer::normalize($rawDomain);
        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new \InvalidArgumentException('Enter a valid website domain (e.g. example.com).');
        }

        return RankwayDomain::query()->firstOrCreate(
            ['domain' => $domain],
            [
                'url' => 'https://'.$domain,
                'country' => (string) config('seo.rankway.default_country', 'IN'),
                'status' => 'pending',
            ]
        );
    }

    /**
     * Analyze a domain. Returns cached result when fresh unless $force.
     */
    public function analyze(string $rawDomain, bool $force = false): RankwayDomain
    {
        $record = $this->findOrCreate($rawDomain);

        $hours = (int) config('seo.rankway.cache_hours', 24);
        if (! $force && $record->isFresh($hours)) {
            return $record->load('latestMetric');
        }

        $record->update([
            'status' => 'analyzing',
            'last_error' => null,
        ]);

        try {
            $probe = $this->probe->probe($record->domain);
            $external = $this->fetchExternalSignals($record->domain);

            $previous = $record->latestMetric;
            $growth = 50;
            if ($previous && $record->rankway_score !== null && $previous->recorded_at) {
                // Neutral until we have two scored snapshots; mild bump if score rose.
                $growth = 55;
            }

            $hasExternalBacklinks = ($external['backlinks'] ?? null) !== null;
            $provider = $external['provider'] ?? 'probe';

            $visibility = $external['visibility_score']
                ?? $this->scoring->scoreFromCount($external['organic_keywords'] ?? null, 5_000);
            $keywordScore = $external['keyword_score']
                ?? $this->scoring->scoreFromCount($external['organic_keywords'] ?? null, 5_000);
            $backlinkScore = $external['backlink_score']
                ?? $this->scoring->scoreFromCount($external['backlinks'] ?? null, 50_000);
            $referringScore = $external['referring_score']
                ?? $this->scoring->scoreFromCount($external['referring_domains'] ?? null, 5_000);

            // Without verified backlink data, do NOT infer authority from on-page SEO.
            if (! $hasExternalBacklinks) {
                $backlinkScore = 22;
                $referringScore = 20;
                $visibility = (int) round(min(52, (($probe['technical_score'] ?? 40) + ($probe['content_score'] ?? 40)) / 2.2));
                $keywordScore = (int) round(min(48, ($probe['content_score'] ?? 40) * 0.45));
            }

            $floored = $this->benchmarks->applyFloors($record->domain, [
                'authority' => (int) round(($backlinkScore * 0.6) + ($referringScore * 0.4)),
                'visibility' => $visibility,
                'backlinks' => $backlinkScore,
                'referring' => $referringScore,
                'keywords' => $keywordScore,
            ]);
            $visibility = $floored['visibility'];
            $keywordScore = $floored['keywords'];
            $backlinkScore = $floored['backlinks'];
            $referringScore = $floored['referring'];
            $authority = $floored['authority'];

            $scored = $this->scoring->score([
                'visibility' => $visibility,
                'keywords' => $keywordScore,
                'backlinks' => $backlinkScore,
                'referring' => $referringScore,
                'technical' => $probe['technical_score'] ?? 50,
                'performance' => $probe['performance_score'] ?? 50,
                'content' => $probe['content_score'] ?? 50,
                'growth' => $growth,
            ]);

            $finalScore = $scored['score'];
            if (! $hasExternalBacklinks && ! $this->benchmarks->floorsFor($record->domain)) {
                $finalScore = min($finalScore, (int) config('seo.rankway.probe_only_score_cap', 72));
            } elseif ($this->benchmarks->floorsFor($record->domain)) {
                $provider = 'benchmark';
            }

            $authority = (int) round(($backlinkScore * 0.6) + ($referringScore * 0.4));

            $metric = RankwayDomainMetric::query()->create([
                'rankway_domain_id' => $record->id,
                'organic_traffic' => $external['organic_traffic'] ?? null,
                'organic_keywords' => $external['organic_keywords'] ?? null,
                'backlinks' => $external['backlinks'] ?? null,
                'referring_domains' => $external['referring_domains'] ?? null,
                'authority_score' => $authority,
                'visibility_score' => $visibility,
                'keyword_score' => $keywordScore,
                'backlink_score' => $backlinkScore,
                'referring_score' => $referringScore,
                'technical_score' => $probe['technical_score'] ?? null,
                'performance_score' => $probe['performance_score'] ?? null,
                'content_score' => $probe['content_score'] ?? null,
                'growth_score' => $growth,
                'probe' => $probe,
                'breakdown' => $scored['breakdown'],
                'provider' => $provider,
                'recorded_at' => now(),
            ]);

            $record->forceFill([
                'url' => $probe['url'] ?? $record->url,
                'title' => $probe['title'] ?? $record->title,
                'rankway_score' => $finalScore,
                'status' => 'ready',
                'last_error' => $probe['ok'] ? null : ($probe['message'] ?? 'Probe incomplete'),
                'last_analyzed_at' => now(),
            ])->save();

            RankwayDomainRankHistory::query()->create([
                'rankway_domain_id' => $record->id,
                'rankway_score' => $finalScore,
                'global_rank' => $record->global_rank,
                'country_rank' => $record->country_rank,
                'category_rank' => $record->category_rank,
                'recorded_at' => now(),
            ]);

            // Fast approximate rank among current index, then full recompute is cheap for MVP sizes.
            $this->ranks->recomputeAll();

            $record->refresh()->load(['latestMetric', 'rankHistory']);

            // Keep history row in sync after recompute.
            RankwayDomainRankHistory::query()
                ->where('rankway_domain_id', $record->id)
                ->latest('id')
                ->limit(1)
                ->update([
                    'global_rank' => $record->global_rank,
                    'country_rank' => $record->country_rank,
                    'category_rank' => $record->category_rank,
                ]);

            unset($metric);

            return $record->load('latestMetric');
        } catch (\Throwable $e) {
            Log::warning('Rankway domain analysis failed', [
                'domain' => $record->domain,
                'error' => $e->getMessage(),
            ]);
            $record->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function linkSeoSite(SeoSite $site, ?RankwayDomain $domain = null): void
    {
        $domain ??= $this->findOrCreate($site->domain);
        if ((int) $site->rankway_domain_id !== (int) $domain->id) {
            $site->forceFill(['rankway_domain_id' => $domain->id])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchExternalSignals(string $domain): array
    {
        if (! DataForSeoClient::configured()) {
            return ['provider' => 'probe'];
        }

        try {
            $provider = app(DataForSeoBacklinkProvider::class);
            $data = $provider->summary($domain, 10);

            return [
                'provider' => $provider->name(),
                'backlinks' => $data['backlinks'] ?? null,
                'referring_domains' => $data['referring_domains'] ?? null,
                'organic_keywords' => null,
                'organic_traffic' => null,
                'backlink_score' => $this->scoring->scoreFromCount($data['backlinks'] ?? 0, 50_000),
                'referring_score' => $this->scoring->scoreFromCount($data['referring_domains'] ?? 0, 5_000),
            ];
        } catch (\Throwable $e) {
            Log::info('Rankway external signals skipped', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return ['provider' => 'probe'];
        }
    }
}
