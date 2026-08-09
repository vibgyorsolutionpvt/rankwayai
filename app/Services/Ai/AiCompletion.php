<?php

namespace App\Services\Ai;

class AiCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly int $tokens = 0,
        public readonly bool $ok = true,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $provider, string $error): self
    {
        return new self('', $provider, 0, false, $error);
    }
}
