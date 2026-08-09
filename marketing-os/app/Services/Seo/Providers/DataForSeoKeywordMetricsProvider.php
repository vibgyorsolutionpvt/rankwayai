<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\KeywordMetricsProvider;
use App\Services\Seo\DataTransfer\KeywordMetric;

class DataForSeoKeywordMetricsProvider implements KeywordMetricsProvider
{
    public function __construct(private DataForSeoClient $client) {}

    public function configured(): bool
    {
        return DataForSeoClient::configured();
    }

    public function name(): string
    {
        return 'dataforseo';
    }

    public function fetch(array $keywords, string $locationName, string $languageCode = 'en'): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(
            fn ($k) => mb_strtolower(trim((string) $k)),
            $keywords
        ))));

        if ($keywords === []) {
            return [];
        }

        $payload = $this->client->post('/v3/dataforseo_labs/google/keyword_overview/live', [[
            'keywords' => $keywords,
            'location_name' => $locationName,
            'language_code' => $languageCode,
        ]]);

        $out = [];
        foreach ($payload['tasks'] as $task) {
            foreach ($task['result'] ?? [] as $result) {
                foreach ($result['items'] ?? [] as $item) {
                    $kw = mb_strtolower(trim((string) ($item['keyword'] ?? '')));
                    if ($kw === '') {
                        continue;
                    }
                    $info = $item['keyword_info'] ?? [];
                    $props = $item['keyword_properties'] ?? [];
                    $out[] = new KeywordMetric(
                        keyword: $kw,
                        searchVolume: isset($info['search_volume']) ? (int) $info['search_volume'] : null,
                        difficulty: isset($props['keyword_difficulty']) ? (int) $props['keyword_difficulty'] : null,
                        cpc: isset($info['cpc']) ? (float) $info['cpc'] : null,
                        competition: isset($info['competition']) ? (float) $info['competition'] : null,
                        provider: $this->name(),
                    );
                }
            }
        }

        return $out;
    }
}
