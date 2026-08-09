<?php

namespace App\Services\Seo\Contracts;

interface SerpLocalProvider
{
    public function configured(): bool;

    public function name(): string;

    /**
     * @return array{our_rank:?int,pack:list<array{rank:int,title:?string,domain:?string,rating:?float,address:?string}>}
     */
    public function localPack(string $keyword, string $locationName, ?string $businessName = null, string $languageCode = 'en'): array;
}
