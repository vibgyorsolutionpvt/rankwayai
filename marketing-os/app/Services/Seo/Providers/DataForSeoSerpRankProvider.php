<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\SerpRankProvider;
use App\Services\Seo\DataTransfer\SerpRankResult;
use App\Support\DomainNormalizer;

class DataForSeoSerpRankProvider implements SerpRankProvider
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

    public function rankFor(
        string $keyword,
        string $targetDomain,
        string $locationName,
        string $languageCode = 'en',
        bool $preferLocalPack = false
    ): SerpRankResult {
        $target = DomainNormalizer::normalize($targetDomain);

        $payload = $this->client->post('/v3/serp/google/organic/live/advanced', [[
            'keyword' => $keyword,
            'location_name' => $locationName,
            'language_code' => $languageCode,
            'depth' => 100,
        ]]);

        $organicPosition = null;
        $localPackPosition = null;
        $matchedUrl = null;
        $items = [];

        foreach ($payload['tasks'] as $task) {
            foreach ($task['result'] ?? [] as $result) {
                $items = $result['items'] ?? [];
                break 2;
            }
        }

        $localIndex = 0;
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type === 'organic') {
                $url = (string) ($item['url'] ?? '');
                $host = DomainNormalizer::normalize((string) (parse_url($url, PHP_URL_HOST) ?: ''));
                if ($host !== '' && ($host === $target || str_ends_with($host, '.'.$target))) {
                    $organicPosition = isset($item['rank_group']) ? (int) $item['rank_group'] : (isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null);
                    $matchedUrl = $url;
                }
            }
            if ($type === 'local_pack') {
                foreach ($item['items'] ?? [$item] as $local) {
                    $localIndex++;
                    $domain = DomainNormalizer::normalize((string) ($local['domain'] ?? $local['url'] ?? ''));
                    if ($domain === '' && isset($local['url'])) {
                        $domain = DomainNormalizer::normalize((string) (parse_url((string) $local['url'], PHP_URL_HOST) ?: ''));
                    }
                    if ($domain !== '' && ($domain === $target || str_ends_with($domain, '.'.$target))) {
                        $localPackPosition = $localIndex;
                    }
                }
            }
        }

        return new SerpRankResult(
            keyword: $keyword,
            organicPosition: $organicPosition,
            localPackPosition: $preferLocalPack ? $localPackPosition : $localPackPosition,
            matchedUrl: $matchedUrl,
            provider: $this->name(),
            meta: ['cost' => $payload['cost'] ?? null],
        );
    }
}
