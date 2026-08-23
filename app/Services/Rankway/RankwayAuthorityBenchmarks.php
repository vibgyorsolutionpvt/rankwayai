<?php

namespace App\Services\Rankway;

/**
 * Minimum authority / score floors for well-known domains when probes under-report
 * (e.g. SPAs, login walls) or before external SEO data is available.
 */
class RankwayAuthorityBenchmarks
{
    /**
     * @return array{authority:int,visibility:int,backlinks:int,referring:int,keywords:int}|null
     */
    public function floorsFor(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        $map = config('seo.rankway.authority_floors', []);

        return is_array($map[$domain] ?? null) ? $map[$domain] : null;
    }

    /**
     * @param  array<string, int>  $scores  factor scores 0–100
     * @return array<string, int>
     */
    public function applyFloors(string $domain, array $scores): array
    {
        $floors = $this->floorsFor($domain);
        if (! $floors) {
            return $scores;
        }

        foreach (['authority', 'visibility', 'backlinks', 'referring', 'keywords'] as $key) {
            if (isset($floors[$key])) {
                $scores[$key] = max((int) ($scores[$key] ?? 0), (int) $floors[$key]);
            }
        }

        return $scores;
    }
}
