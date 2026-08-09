<?php

namespace App\Services\Seo\Contracts;

interface JsRenderProvider
{
    public function configured(): bool;

    public function name(): string;

    /**
     * @return array{html:string,status:int,load_time_ms:?int}
     */
    public function fetch(string $url): array;
}
