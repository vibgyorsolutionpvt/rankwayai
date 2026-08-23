<?php

namespace App\Jobs;

use App\Services\Rankway\RankwayDomainAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeRankwayDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public string $domain,
        public bool $force = false,
    ) {}

    public function handle(RankwayDomainAnalyzer $analyzer): void
    {
        $analyzer->analyze($this->domain, $this->force);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('AnalyzeRankwayDomainJob failed', [
            'domain' => $this->domain,
            'error' => $e?->getMessage(),
        ]);
    }
}
