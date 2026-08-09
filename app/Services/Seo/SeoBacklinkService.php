<?php

namespace App\Services\Seo;

use App\Models\SeoBacklink;
use App\Models\SeoSite;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Providers\DataForSeoBacklinkProvider;
use App\Services\Seo\Providers\DataForSeoClient;
use RuntimeException;

class SeoBacklinkService
{
    public function __construct(
        private PlanAccess $plans,
        private SeoApiUsageLogger $usage,
    ) {}

    public function sync(Workspace $workspace, SeoSite $site): array
    {
        if (! $this->plans->allows($workspace, 'seo_backlinks')) {
            throw new RuntimeException($this->plans->denyMessage('seo_backlinks'));
        }
        if (! DataForSeoClient::configured()) {
            throw new RuntimeException('DataForSEO is not configured.');
        }

        $provider = app(DataForSeoBacklinkProvider::class);
        $data = $provider->summary($site->domain, 50);

        $site->update([
            'backlinks' => $data['backlinks'],
            'referring_domains' => $data['referring_domains'],
            'dofollow_backlinks' => $data['dofollow'],
            'backlinks_synced_at' => now(),
            'backlinks_provider' => $provider->name(),
            'backlink_summary' => $data['summary'],
        ]);

        SeoBacklink::query()->where('seo_site_id', $site->id)->delete();
        foreach ($data['links'] as $link) {
            SeoBacklink::query()->create([
                'workspace_id' => $workspace->id,
                'seo_site_id' => $site->id,
                'source_url' => $link['source_url'],
                'source_domain' => $link['source_domain'],
                'target_url' => $link['target_url'],
                'anchor' => $link['anchor'],
                'dofollow' => $link['dofollow'],
                'domain_rank' => $link['domain_rank'],
                'status' => 'active',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $this->usage->log($workspace, $provider->name(), 'backlinks_sync', 1, $data['cost'] ?? null, [
            'domain' => $site->domain,
            'links' => count($data['links']),
        ]);

        return [
            'backlinks' => $data['backlinks'],
            'referring_domains' => $data['referring_domains'],
            'links_stored' => count($data['links']),
            'message' => 'Backlink profile synced for '.$site->domain,
        ];
    }
}
