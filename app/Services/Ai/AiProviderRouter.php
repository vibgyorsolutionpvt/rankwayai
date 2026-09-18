<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Cache;

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

        // Prefer last successful provider when healthy (saves free-tier quota)
        $sticky = Cache::get($this->stickyKey());
        if (is_string($sticky) && isset($this->providers[$sticky]) && ! $this->isCoolingDown($sticky)) {
            // Never sticky-lock onto free APIs when a paid primary is configured —
            // that caused prod to keep hitting mistral/cerebras instead of OpenAI.
            if (! $this->shouldIgnoreSticky($sticky)) {
                $provider = $this->providers[$sticky];
                if ($provider->configured()) {
                    return $provider;
                }
            }
        }

        foreach ($this->priority() as $id) {
            $provider = $this->providers[$id] ?? null;
            if ($provider?->configured() && ! $this->isCoolingDown($id)) {
                return $provider;
            }
        }

        // All cooling — still return first configured so complete() can retry
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
        $queue = $this->buildAttemptQueue();
        if ($queue === []) {
            $preferred = (string) config('ai.default', 'openai');
            $error = 'No live AI provider configured';
            if (in_array($preferred, ['openai', 'openrouter'], true)) {
                $error = strtoupper($preferred).'_API_KEY missing — free backups blocked. Set the paid key in production .env, then Admin → AI logs → Clear failover.';
            }

            return AiCompletion::failed('template', $error);
        }

        $maxAttempts = max(1, (int) config('ai.failover.max_attempts', 3));
        $attempts = [];
        $lastFailure = null;

        foreach (array_slice($queue, 0, $maxAttempts) as $provider) {
            $result = $provider->complete($system, $user, $maxTokens);
            $attempts[] = $this->attemptSnapshot($result);

            if ($result->ok) {
                $this->rememberSuccess($provider->name());

                return $this->withAttempts($result, $attempts);
            }

            $this->tripBreaker($result);
            $lastFailure = $result;
        }

        $tried = implode(' → ', array_map(
            fn (array $a) => ($a['provider'] ?? '?').(isset($a['http_status']) ? ' HTTP '.$a['http_status'] : ''),
            $attempts,
        ));

        $error = ($lastFailure?->error ?: 'All AI providers failed').' | tried: '.$tried;

        return $this->withAttempts(
            AiCompletion::failed(
                $lastFailure?->provider ?? 'template',
                $error,
                $lastFailure?->apiUrl,
                $lastFailure?->httpStatus,
                $lastFailure?->requestPayload,
                $lastFailure?->rawResponse,
                $lastFailure?->model,
            ),
            $attempts,
        );
    }

    /**
     * Sticky winner first, then config priority order (skip cooling). Caps applied in complete().
     *
     * @return list<AiProvider>
     */
    private function buildAttemptQueue(): array
    {
        $queue = [];
        $seen = [];

        $push = function (?AiProvider $provider) use (&$queue, &$seen): void {
            if (! $provider || ! $provider->configured()) {
                return;
            }
            $id = $provider->name();
            if (isset($seen[$id]) || $this->isCoolingDown($id)) {
                return;
            }
            $seen[$id] = true;
            $queue[] = $provider;
        };

        $push($this->resolve());

        foreach ($this->configuredInPriorityOrder() as $provider) {
            $push($provider);
        }

        // Everything cooling — allow one retry of preferred / first configured
        if ($queue === []) {
            $push($this->providers[$this->priority()[0] ?? ''] ?? null);
            foreach ($this->configuredInPriorityOrder() as $provider) {
                if ($queue !== []) {
                    break;
                }
                $id = $provider->name();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $queue[] = $provider;
            }
        }

        // Forced paid primary with no paid keys → do not silently burn free APIs
        // (that produced mistral 429 → cerebras 404 → template garbage on prod).
        $preferred = (string) config('ai.default', 'openai');
        if (in_array($preferred, ['openai', 'openrouter'], true)) {
            $hasPaid = ($this->providers['openai'] ?? null)?->configured()
                || ($this->providers['openrouter'] ?? null)?->configured();
            if (! $hasPaid) {
                return [];
            }
        }

        return $queue;
    }

    private function isCoolingDown(string $id): bool
    {
        return Cache::has($this->cooldownKey($id));
    }

    private function tripBreaker(AiCompletion $result): void
    {
        $status = $result->httpStatus;
        $error = mb_strtolower((string) ($result->error ?? ''));
        $shouldTrip = in_array($status, [401, 403, 404, 429, 503], true)
            || str_contains($error, 'rate')
            || str_contains($error, 'quota')
            || str_contains($error, 'high demand')
            || str_contains($error, 'no longer available')
            || str_contains($error, 'does not exist')
            || str_contains($error, 'no credits');

        if (! $shouldTrip) {
            return;
        }

        $seconds = (int) config('ai.failover.cooldown_seconds', 900);
        // Rate limits / overload — shorter pause; auth/model gone — longer
        if (in_array($status, [401, 403, 404], true) || str_contains($error, 'does not exist') || str_contains($error, 'no credits')) {
            $seconds = max($seconds, 3600);
        }

        Cache::put($this->cooldownKey($result->provider), true, now()->addSeconds($seconds));
    }

    private function rememberSuccess(string $id): void
    {
        // Do not sticky-lock free backups when OpenAI/OpenRouter is the intended primary.
        if (! $this->shouldIgnoreSticky($id)) {
            Cache::put(
                $this->stickyKey(),
                $id,
                now()->addSeconds((int) config('ai.failover.sticky_ttl_seconds', 3600)),
            );
        }
        Cache::forget($this->cooldownKey($id));
    }

    /**
     * Clear sticky winner + all provider cooldowns (admin recovery after bad keys).
     */
    public function clearFailoverState(): void
    {
        Cache::forget($this->stickyKey());
        foreach (array_keys($this->providers) as $id) {
            Cache::forget($this->cooldownKey($id));
        }
    }

    private function shouldIgnoreSticky(string $id): bool
    {
        $tier = (string) (config("ai.providers.{$id}.tier") ?? 'free');
        if ($tier !== 'free') {
            return false;
        }

        $preferred = (string) config('ai.default', 'openai');
        if (! in_array($preferred, ['openai', 'openrouter', 'auto'], true)) {
            return false;
        }

        $openai = $this->providers['openai'] ?? null;
        $openrouter = $this->providers['openrouter'] ?? null;

        return ($openai?->configured() ?? false) || ($openrouter?->configured() ?? false);
    }

    private function stickyKey(): string
    {
        return 'ai:last_success_provider';
    }

    private function cooldownKey(string $id): string
    {
        return 'ai:cooldown:'.$id;
    }

    /**
     * @return list<AiProvider>
     */
    private function configuredInPriorityOrder(): array
    {
        $out = [];
        foreach ($this->priority() as $id) {
            $provider = $this->providers[$id] ?? null;
            if ($provider?->configured()) {
                $out[] = $provider;
            }
        }

        foreach ($this->providers as $id => $provider) {
            if (! $provider->configured()) {
                continue;
            }
            if (collect($out)->contains(fn (AiProvider $p) => $p->name() === $id)) {
                continue;
            }
            $out[] = $provider;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     */
    private function withAttempts(AiCompletion $completion, array $attempts): AiCompletion
    {
        return new AiCompletion(
            $completion->text,
            $completion->provider,
            $completion->tokens,
            $completion->ok,
            $completion->error,
            $completion->apiUrl,
            $completion->httpStatus,
            $completion->requestPayload,
            $completion->rawResponse,
            $completion->model,
            $attempts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptSnapshot(AiCompletion $completion): array
    {
        return [
            'provider' => $completion->provider,
            'api_url' => $completion->apiUrl,
            'model' => $completion->model,
            'http_status' => $completion->httpStatus,
            'ok' => $completion->ok,
            'error' => $completion->error,
            'tokens' => $completion->tokens,
        ];
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
