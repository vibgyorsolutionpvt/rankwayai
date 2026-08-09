<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\JsRenderProvider;
use RuntimeException;

/**
 * Fallback when Browserless is not configured — never invents HTML.
 */
class NullJsRenderProvider implements JsRenderProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function fetch(string $url): array
    {
        throw new RuntimeException('JS render provider is not configured.');
    }
}
