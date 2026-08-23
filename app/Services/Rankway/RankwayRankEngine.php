<?php

namespace App\Services\Rankway;

use App\Models\RankwayDomain;
use App\Models\RankwayDomainMetric;
use Illuminate\Support\Facades\DB;

/**
 * Assigns estimated global / country / category ranks among indexed domains.
 */
class RankwayRankEngine
{
    public function __construct(
        private RankwayScoringEngine $scoring,
    ) {}

    /**
     * Weighted sort key used for ranking (not the display Rankway Score).
     *
     * @param  array<string, mixed>  $metric
     */
    public function rankSortKey(array $metric): float
    {
        $traffic = $this->logNorm((int) ($metric['organic_traffic'] ?? 0), 1_000_000);
        $keywords = $this->logNorm((int) ($metric['organic_keywords'] ?? 0), 50_000);
        $authority = ((int) ($metric['authority_score'] ?? 50)) / 100;
        $referring = $this->logNorm((int) ($metric['referring_domains'] ?? 0), 10_000);
        $visibility = ((int) ($metric['visibility_score'] ?? 50)) / 100;
        $technical = ((int) ($metric['technical_score'] ?? 50)) / 100;
        $growth = ((int) ($metric['growth_score'] ?? 50)) / 100;

        return (0.35 * $traffic)
            + (0.20 * $keywords)
            + (0.15 * $authority)
            + (0.10 * $referring)
            + (0.10 * $visibility)
            + (0.05 * $technical)
            + (0.05 * $growth);
    }

    /**
     * Recompute ranks for all ready domains (or a single country/category slice).
     *
     * @return array{updated:int}
     */
    public function recomputeAll(): array
    {
        $domains = RankwayDomain::query()
            ->where('status', 'ready')
            ->whereNotNull('rankway_score')
            ->with('latestMetric')
            ->get();

        $scored = $domains->sortByDesc(fn (RankwayDomain $domain) => $domain->rankway_score ?? 0)->values();

        $updated = 0;
        $global = 0;
        $countryCounters = [];
        $categoryCounters = [];

        DB::transaction(function () use ($scored, &$updated, &$global, &$countryCounters, &$categoryCounters) {
            foreach ($scored as $domain) {
                $global++;
                /** @var RankwayDomain $domain */
                $country = (string) ($domain->country ?: 'IN');
                $category = $domain->category;

                $countryCounters[$country] = ($countryCounters[$country] ?? 0) + 1;
                $countryRank = $countryCounters[$country];

                $categoryRank = null;
                if (filled($category)) {
                    $categoryCounters[$category] = ($categoryCounters[$category] ?? 0) + 1;
                    $categoryRank = $categoryCounters[$category];
                }

                $domain->forceFill([
                    'global_rank' => $global,
                    'country_rank' => $countryRank,
                    'category_rank' => $categoryRank,
                ])->save();

                $updated++;
            }
        });

        return ['updated' => $updated];
    }

    private function logNorm(int $value, int $softCap): float
    {
        $value = max(0, $value);

        return min(1.0, log(1 + $value) / log(1 + max(1, $softCap)));
    }
}
