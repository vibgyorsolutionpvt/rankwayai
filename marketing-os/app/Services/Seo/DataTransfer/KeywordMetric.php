<?php

namespace App\Services\Seo\DataTransfer;

final class KeywordMetric
{
    public function __construct(
        public string $keyword,
        public ?int $searchVolume = null,
        public ?int $difficulty = null,
        public ?float $cpc = null,
        public ?float $competition = null,
        public string $provider = 'dataforseo',
    ) {}
}
