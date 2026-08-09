<?php

namespace App\Services\Seo\Contracts;

interface BacklinkProvider
{
    public function configured(): bool;

    public function name(): string;

    /**
     * @return array{
     *   backlinks:?int,
     *   referring_domains:?int,
     *   dofollow:?int,
     *   summary:array<string,mixed>,
     *   links:list<array{source_url:string,source_domain:?string,target_url:?string,anchor:?string,dofollow:bool,domain_rank:?int}>
     * }
     */
    public function summary(string $domain, int $linkLimit = 50): array;
}
