<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;

class AiProviderRouter
{
    /** @var array<string, AiProvider> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [];

        foreach (array_keys(config('ai.providers', [])) as $id) {
            $this->providers[$id] = $id === 'gemini'
                ? new GeminiProvider
                : new OpenAiCompatibleProvider($id);
        }
    }

    /**
     * @return list<array{id:string,label:string,tier:string,configured:bool,model:?string}>
     */
    public function status(): array
    {
        $out = [];
        foreach (config('ai.providers', []) as $id => $cfg) {
            $out[] = [
                'id' => $id,
                'label' => $cfg['label'] ?? $id,
                'tier' => $cfg['tier'] ?? 'free',
                'configured' => ($this->providers[$id] ?? null)?->configured() ?? false,
                'model' => $cfg['model'] ?? null,
            ];
        }

        return $out;
    }

    public function anyConfigured(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->configured()) {
                return true;
            }
        }

        return false;
    }

    public function activeName(): string
    {
        return $this->resolve()?->name() ?? 'template';
    }

    public function resolve(): ?AiProvider
    {
        $preferred = config('ai.default', 'auto');

        if ($preferred === 'template') {
            return null;
        }

        if ($preferred !== 'auto' && isset($this->providers[$preferred])) {
            $provider = $this->providers[$preferred];

            return $provider->configured() ? $provider : null;
        }

        foreach ($this->priority() as $id) {
            $provider = $this->providers[$id] ?? null;
            if ($provider?->configured()) {
                return $provider;
            }
        }

        return null;
    }

    public function complete(string $system, string $user, int $maxTokens = 600): AiCompletion
    {
        $provider = $this->resolve();
        if (! $provider) {
            return AiCompletion::failed('template', 'No live AI provider configured');
        }

        $result = $provider->complete($system, $user, $maxTokens);
        if ($result->ok) {
            return $result;
        }

        foreach ($this->priority() as $id) {
            $fallback = $this->providers[$id] ?? null;
            if (! $fallback || $fallback->name() === $provider->name() || ! $fallback->configured()) {
                continue;
            }
            $retry = $fallback->complete($system, $user, $maxTokens);
            if ($retry->ok) {
                return $retry;
            }
        }

        return $result;
    }

    public function costFor(?string $provider = null): float
    {
        $provider ??= $this->activeName();
        $costs = config('ai.costs', []);

        return (float) ($costs[$provider] ?? $costs['template'] ?? 0.002);
    }

    /**
     * @return list<string>
     */
    private function priority(): array
    {
        return config('ai.priority', array_keys(config('ai.providers', [])));
    }
}
