<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\BacklinkProvider;

class DataForSeoBacklinkProvider implements BacklinkProvider
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

    public function summary(string $domain, int $linkLimit = 50): array
    {
        $summaryPayload = $this->client->post('/v3/backlinks/summary/live', [[
            'target' => $domain,
            'include_subdomains' => true,
            'backlinks_status_type' => 'live',
        ]]);

        $summary = [];
        foreach ($summaryPayload['tasks'] as $task) {
            foreach ($task['result'] ?? [] as $result) {
                $summary = $result;
                break 2;
            }
        }

        $linksPayload = $this->client->post('/v3/backlinks/backlinks/live', [[
            'target' => $domain,
            'mode' => 'as_is',
            'limit' => max(1, min(100, $linkLimit)),
            'order_by' => ['rank,desc'],
        ]]);

        $links = [];
        foreach ($linksPayload['tasks'] as $task) {
            foreach ($task['result'] ?? [] as $result) {
                foreach ($result['items'] ?? [] as $item) {
                    $links[] = [
                        'source_url' => (string) ($item['url_from'] ?? ''),
                        'source_domain' => (string) ($item['domain_from'] ?? ''),
                        'target_url' => (string) ($item['url_to'] ?? ''),
                        'anchor' => (string) ($item['anchor'] ?? ''),
                        'dofollow' => (bool) ($item['dofollow'] ?? true),
                        'domain_rank' => isset($item['rank']) ? (int) $item['rank'] : null,
                    ];
                }
            }
        }

        return [
            'backlinks' => isset($summary['backlinks']) ? (int) $summary['backlinks'] : null,
            'referring_domains' => isset($summary['referring_domains']) ? (int) $summary['referring_domains'] : null,
            'dofollow' => isset($summary['referring_links_dofollow'])
                ? (int) $summary['referring_links_dofollow']
                : (isset($summary['backlinks']) ? null : null),
            'summary' => $summary,
            'links' => array_values(array_filter($links, fn ($l) => $l['source_url'] !== '')),
            'cost' => (float) (($summaryPayload['cost'] ?? 0) + ($linksPayload['cost'] ?? 0)),
        ];
    }
}
