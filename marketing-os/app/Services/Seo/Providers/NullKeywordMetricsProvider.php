<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\KeywordMetricsProvider;
use App\Services\Seo\DataTransfer\KeywordMetric;

/**
 * Used when DataForSEO is not configured — returns no metrics (never invents volume/KD).
 */
class NullKeywordMetricsProvider implements KeywordMetricsProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function fetch(array $keywords, string $locationName, string $languageCode = 'en'): array
    {
        return [];
    }
}
