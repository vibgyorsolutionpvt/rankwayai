<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiCompletion;

interface AiProvider
{
    public function name(): string;

    public function configured(): bool;

    public function complete(string $system, string $user, int $maxTokens = 600): AiCompletion;
}
