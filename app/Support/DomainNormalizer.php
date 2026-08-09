<?php

namespace App\Support;

class DomainNormalizer
{
    public static function normalize(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0] ?? $domain;
        $domain = explode('?', $domain)[0] ?? $domain;

        return rtrim($domain, '.');
    }
}
