<?php

namespace App\Services\Seo\DataTransfer;

final class SerpRankResult
{
    public function __construct(
        public string $keyword,
        public ?int $organicPosition = null,
        public ?int $localPackPosition = null,
        public ?string $matchedUrl = null,
        public string $provider = 'stub',
        public array $meta = [],
    ) {}
}
