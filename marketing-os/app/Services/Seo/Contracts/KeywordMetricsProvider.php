<?php

namespace App\Services\Seo\Contracts;

use App\Services\Seo\DataTransfer\KeywordMetric;

interface KeywordMetricsProvider
{
    public function configured(): bool;

    public function name(): string;

    /**
     * @param  list<string>  $keywords
     * @return list<KeywordMetric>
     */
    public function fetch(array $keywords, string $locationName, string $languageCode = 'en'): array;
}
