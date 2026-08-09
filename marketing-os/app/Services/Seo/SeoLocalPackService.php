<?php

namespace App\Services\Seo;

use App\Models\SeoLocalSnapshot;
use App\Models\SeoLocalTarget;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Services\Seo\Providers\DataForSeoSerpLocalProvider;
use RuntimeException;

class SeoLocalPackService
{
    public function __construct(
        private PlanAccess $plans,
        private SeoApiUsageLogger $usage,
    ) {}

    public function track(Workspace $workspace, SeoLocalTarget $target): SeoLocalSnapshot
    {
        if (! $this->plans->allows($workspace, 'seo_local')) {
            throw new RuntimeException($this->plans->denyMessage('seo_local'));
        }
        if (! DataForSeoClient::configured()) {
            throw new RuntimeException('DataForSEO is not configured.');
        }

        $provider = app(DataForSeoSerpLocalProvider::class);
        $result = $provider->localPack(
            $target->keyword,
            $target->location_name,
            $target->business_name,
            (string) config('seo.default_language', 'en')
        );

        $snapshot = SeoLocalSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'seo_local_target_id' => $target->id,
            'our_rank' => $result['our_rank'],
            'pack_json' => $result['pack'],
            'checked_at' => now(),
            'provider' => $provider->name(),
        ]);

        $this->usage->log($workspace, $provider->name(), 'local_pack', 1, $result['cost'] ?? null, [
            'keyword' => $target->keyword,
            'location' => $target->location_name,
            'our_rank' => $result['our_rank'],
        ]);

        return $snapshot;
    }
}
