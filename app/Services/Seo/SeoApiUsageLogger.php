<?php

namespace App\Services\Seo;

use App\Models\SeoApiUsageLog;
use App\Models\Workspace;

class SeoApiUsageLogger
{
    public function log(
        Workspace $workspace,
        string $provider,
        string $operation,
        int $units = 1,
        ?float $costUsd = null,
        array $meta = []
    ): SeoApiUsageLog {
        return SeoApiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => $provider,
            'operation' => $operation,
            'units' => $units,
            'cost_usd' => $costUsd,
            'meta' => $meta,
        ]);
    }
}
