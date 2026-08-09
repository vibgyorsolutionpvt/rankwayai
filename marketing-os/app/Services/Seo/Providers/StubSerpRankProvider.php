<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\SerpRankProvider;
use App\Services\Seo\DataTransfer\SerpRankResult;

/**
 * Demo/dev stub — random walk ranks. Never use when DataForSEO is live + plan allows.
 */
class StubSerpRankProvider implements SerpRankProvider
{
    public function configured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'stub';
    }

    public function rankFor(
        string $keyword,
        string $targetDomain,
        string $locationName,
        string $languageCode = 'en',
        bool $preferLocalPack = false
    ): SerpRankResult {
        $position = random_int(5, 40);

        return new SerpRankResult(
            keyword: $keyword,
            organicPosition: $position,
            localPackPosition: $preferLocalPack ? random_int(1, 3) : null,
            matchedUrl: null,
            provider: $this->name(),
            meta: ['note' => 'stub'],
        );
    }
}
