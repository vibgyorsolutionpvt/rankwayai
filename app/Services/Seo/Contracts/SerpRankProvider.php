<?php

namespace App\Services\Seo\Contracts;

use App\Services\Seo\DataTransfer\SerpRankResult;

interface SerpRankProvider
{
    public function configured(): bool;

    public function name(): string;

    public function rankFor(
        string $keyword,
        string $targetDomain,
        string $locationName,
        string $languageCode = 'en',
        bool $preferLocalPack = false
    ): SerpRankResult;
}
