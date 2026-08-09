<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\SerpLocalProvider;
use App\Support\DomainNormalizer;

class DataForSeoSerpLocalProvider implements SerpLocalProvider
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

    public function localPack(string $keyword, string $locationName, ?string $businessName = null, string $languageCode = 'en'): array
    {
        $payload = $this->client->post('/v3/serp/google/local_finder/live/advanced', [[
            'keyword' => $keyword,
            'location_name' => $locationName,
            'language_code' => $languageCode,
            'depth' => 20,
        ]]);

        $pack = [];
        $rank = 0;
        foreach ($payload['tasks'] as $task) {
            foreach ($task['result'] ?? [] as $result) {
                foreach ($result['items'] ?? [] as $item) {
                    if (($item['type'] ?? '') !== 'local_pack' && ($item['type'] ?? '') !== 'maps_search' && ($item['type'] ?? '') !== 'local_finder') {
                        // local_finder items are often type "local_pack" children or direct "maps_search"
                        if (! in_array($item['type'] ?? '', ['local_pack', 'maps_search', 'organic'], true)
                            && empty($item['title'])) {
                            continue;
                        }
                    }

                    $entries = ! empty($item['items']) ? $item['items'] : [$item];
                    foreach ($entries as $entry) {
                        if (empty($entry['title']) && empty($entry['domain'])) {
                            continue;
                        }
                        $rank++;
                        $pack[] = [
                            'rank' => $rank,
                            'title' => $entry['title'] ?? null,
                            'domain' => isset($entry['domain'])
                                ? DomainNormalizer::normalize((string) $entry['domain'])
                                : (isset($entry['url']) ? DomainNormalizer::normalize((string) (parse_url((string) $entry['url'], PHP_URL_HOST) ?: '')) : null),
                            'rating' => isset($entry['rating']['value']) ? (float) $entry['rating']['value'] : (isset($entry['rating']) && is_numeric($entry['rating']) ? (float) $entry['rating'] : null),
                            'address' => $entry['address'] ?? null,
                        ];
                    }
                }
            }
        }

        $ourRank = null;
        if ($businessName) {
            $needle = mb_strtolower($businessName);
            foreach ($pack as $row) {
                if (isset($row['title']) && str_contains(mb_strtolower($row['title']), $needle)) {
                    $ourRank = $row['rank'];
                    break;
                }
            }
        }

        return [
            'our_rank' => $ourRank,
            'pack' => $pack,
            'cost' => (float) ($payload['cost'] ?? 0),
        ];
    }
}
